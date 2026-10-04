<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import { onBeforeRouteLeave, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { AlertCircle, ArrowLeft, Download, EyeOff, Frame, Grid3x3, LayoutTemplate, Layers, Lock, Plus, Redo2, Save, Undo2, Upload, ZoomIn, ZoomOut } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import DesignerCanvas from './designer/DesignerCanvas.vue';
import AlignTools from './designer/AlignTools.vue';
import ElementPalette from './designer/ElementPalette.vue';
import ElementSettings from './designer/ElementSettings.vue';
import PreviewPicker, { type PreviewData } from './designer/PreviewPicker.vue';
import StarterPicker from './designer/StarterPicker.vue';
import { buildLayoutFile, downloadLayout, importLayout, parseLayoutFile, type LayoutFile } from './designer/transfer';
import { sampleValue } from './designer/sample';
import { useDesigner, type DesignerItem } from './designer/useDesigner';
import { useConfirm } from '@/composables/useConfirm';
import { toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { CardSide, DesignElementType } from '@/types';

const { t, te } = useI18n();
const route = useRoute();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();
const designer = useDesigner();
const { template, catalog, items, side, sideItems, selected, selectedKey, selectedKeys, selection, loading, saving, errors, dirty, size, canUndo, canRedo } = designer;

/** Grid step (mm) for snapping while dragging; 0 = off. */
const SNAP_STEPS = [0, 0.5, 1, 2, 5];
const snap = ref(0);
/** Safe-area and centre-line guides. */
const guides = ref(false);

/** Operators may look at a design but not change it. */
const editable = computed(() => auth.can('templates.update'));
const invalidKeys = computed(() => Object.keys(errors.value).map(Number).filter((key) => key !== 0));
const hasBackground = computed(() => sideItems.value.some((item) => item.type === 'background'));

/** Screen pixels per millimetre. */
const ZOOM_STEPS = [3, 4, 5, 6, 7, 8, 10, 12];
/** 100% = the card's real size on a 96 dpi screen. */
const ACTUAL_SIZE = 96 / 25.4;
const zoom = ref(3);
const scale = computed(() => ZOOM_STEPS[zoom.value]);
const stage = useTemplateRef<HTMLElement>('stage');

/** Largest zoom at which the whole card fits the canvas area and the window height. */
function fitZoom(): void {
    const roomWidth = (stage.value?.clientWidth ?? 600) - 48;
    const roomHeight = Math.max(300, window.innerHeight - 320);
    let fitting = 0;
    ZOOM_STEPS.forEach((step, index) => {
        if (step * size.value.width <= roomWidth && step * size.value.height <= roomHeight) {
            fitting = index;
        }
    });
    zoom.value = fitting;
}

// A single-sided template still shows the back while old back elements remain, so they can be removed.
const sides = computed<CardSide[]>(() => (template.value?.is_double_sided || items.value.some((item) => item.side === 'back') ? ['front', 'back'] : ['front']));

onMounted(async () => {
    await designer.load(String(route.params.id));
    requestAnimationFrame(fitZoom);
});

function add(type: DesignElementType, preset?: string): void {
    designer.add(type, t('designer.defaultText'), preset);
}

/*
 * Layout files and ready-made designs.
 */
const startersOpen = ref(false);
const transferring = ref(false);
const importInput = useTemplateRef<HTMLInputElement>('importInput');

async function exportLayout(): Promise<void> {
    if (!template.value) {
        return;
    }
    transferring.value = true;
    try {
        downloadLayout(await buildLayoutFile(items.value, size.value), template.value);
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    } finally {
        transferring.value = false;
    }
}

/** Put a layout file on this template; the back is dropped on single-sided templates. */
async function applyLayout(file: LayoutFile): Promise<void> {
    if (!template.value || (items.value.length && !(await confirm({ title: t('designer.replaceConfirm'), confirmLabel: t('designer.replace') })))) {
        return;
    }
    transferring.value = true;
    try {
        const sides = template.value.is_double_sided ? file.elements : file.elements.filter((element) => element.side === 'front');
        const { elements, scaled, turned } = await importLayout({ ...file, elements: sides }, template.value.id, size.value);
        designer.replaceAll(elements);
        designer.showSide('front');
        startersOpen.value = false;
        ui.notify(t(turned ? 'designer.importedTurned' : scaled ? 'designer.importedScaled' : 'designer.imported'));
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    } finally {
        transferring.value = false;
    }
}

async function importFile(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const chosen = input.files?.[0];
    input.value = '';
    if (!chosen) {
        return;
    }
    try {
        await applyLayout(parseLayoutFile(await chosen.text()));
    } catch {
        ui.notify(t('designer.importInvalid'), 'error');
    }
}

function copyToOtherSide(key: number): void {
    if (designer.copyToOtherSide(key)) {
        ui.notify(t('designer.copiedToOtherSide'));
    } else {
        ui.notify(t('designer.backgroundExistsOtherSide'), 'error');
    }
}

/** Remove every selected element (locked ones stay). */
function remove(): void {
    for (const key of [...selectedKeys.value]) {
        designer.remove(key);
    }
    designer.selectMany(selectedKeys.value.filter((key) => items.value.some((item) => item.key === key)));
}

function isTyping(target: EventTarget | null): boolean {
    return target instanceof HTMLElement && !!target.closest('input, textarea, select, [contenteditable="true"]');
}

/**
 * Shortcuts: arrows move 1 mm (Shift: 0.1 mm), Delete removes, Ctrl+D copies, Ctrl+Z / Ctrl+Shift+Z / Ctrl+Y
 * undo and redo, Ctrl+S saves, Esc clears the selection. Ignored while typing in a field.
 */
function onKeydown(event: KeyboardEvent): void {
    if (!editable.value || isTyping(event.target) || document.querySelector('[role="dialog"]')) {
        return;
    }
    const key = event.key.toLowerCase();
    const command = event.ctrlKey || event.metaKey;
    if (command && (key === 'z' || key === 'y')) {
        event.preventDefault();
        if (key === 'y' || event.shiftKey) {
            designer.redo();
        } else {
            designer.undo();
        }

        return;
    }
    if (command && key === 's') {
        event.preventDefault();
        if (dirty.value && !saving.value) {
            save();
        }

        return;
    }
    if (command && key === 'a') {
        event.preventDefault();
        designer.selectMany(sideItems.value.filter((item) => item.type !== 'background').map((item) => item.key));

        return;
    }
    if (selectedKey.value === null) {
        return;
    }
    const step = event.shiftKey ? 0.1 : 1;
    const moves: Record<string, [number, number]> = { arrowleft: [-step, 0], arrowright: [step, 0], arrowup: [0, -step], arrowdown: [0, step] };
    if (moves[key]) {
        event.preventDefault();
        designer.moveBy(selectedKeys.value, ...moves[key]);
    } else if (key === 'delete' || key === 'backspace') {
        event.preventDefault();
        remove();
    } else if (command && key === 'd') {
        event.preventDefault();
        for (const selectedItem of selection.value) {
            designer.duplicate(selectedItem.key);
        }
    } else if (key === 'escape') {
        designer.select(null);
    }
}
window.addEventListener('keydown', onKeydown);
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

async function save(): Promise<void> {
    if (await designer.save()) {
        ui.notify(t('common.saved'));
    } else {
        const firstInvalid = items.value.find((item) => invalidKeys.value.includes(item.key));
        if (firstInvalid) {
            designer.showSide(firstInvalid.side);
            selectedKey.value = firstInvalid.key;
        }
        ui.notify(t('designer.saveFailed'), 'error');
    }
}

/** Warn before unsaved changes are lost (in-app navigation and closing the tab). */
onBeforeRouteLeave(async () => !dirty.value || (await confirm({ title: t('designer.leaveConfirm'), confirmLabel: t('designer.leave'), danger: true })));
function warnOnUnload(event: BeforeUnloadEvent): void {
    if (dirty.value) {
        event.preventDefault();
    }
}
window.addEventListener('beforeunload', warnOnUnload);
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnOnUnload));

function fieldLabel(field: string | null): string {
    if (!field) {
        return '';
    }
    const custom = catalog.value?.custom_fields.find((item) => item.key === field);
    if (custom) {
        return custom.label;
    }

    return te(`designer.fields.${field}`) ? t(`designer.fields.${field}`) : field;
}

/** Real data of a chosen cardholder; null shows made-up sample data. */
const preview = ref<PreviewData | null>(null);

function valueOf(item: DesignerItem): string {
    const sample = sampleValue(item.field, { 'organization.name': auth.tenant?.name ?? '' });
    if (!preview.value || !item.field) {
        return sample;
    }

    // Card numbers and dates exist only once a card is issued, so they stay as samples.
    return item.field.startsWith('card.') ? sample : (preview.value.fields[item.field] ?? '');
}

function imageOf(item: DesignerItem): string | null {
    const images = preview.value?.images;

    return images && (item.type === 'photo' || item.type === 'cardholder_signature' || item.type === 'signatory_signature') ? images[item.type] : null;
}

/** Short name for the layer list. */
function itemTitle(item: DesignerItem): string {
    if (item.type === 'field') {
        return fieldLabel(item.field) || t('designer.types.field');
    }
    if (item.type === 'text') {
        return item.content || t('designer.types.text');
    }
    if (item.type === 'shape') {
        if (item.style.shape === 'ellipse') {
            return t('designer.shapes.ellipse');
        }

        return t(Math.min(item.width, item.height) <= 1 ? 'designer.presets.line' : 'designer.shapes.rectangle');
    }

    return t(`designer.types.${item.type}`);
}
</script>

<template>
    <div v-if="template" class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <RouterLink :to="{ name: 'card-templates' }" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :title="t('common.back')">
                <ArrowLeft class="size-5" />
            </RouterLink>
            <div class="min-w-0">
                <h1 class="truncate text-lg font-semibold text-fg">{{ template.name }}</h1>
                <p class="text-xs text-muted">{{ t('designer.subtitle', { width: size.width, height: size.height }) }}</p>
            </div>
            <UiBadge>{{ t('templates.version', { n: template.version }) }}</UiBadge>

            <div class="ml-auto flex items-center gap-2">
                <div v-if="sides.length > 1" class="inline-flex rounded-lg border border-line bg-surface p-0.5" role="tablist">
                    <button
                        v-for="value in sides"
                        :key="value"
                        role="tab"
                        :aria-selected="side === value"
                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="side === value ? 'bg-brand text-brand-contrast' : 'text-muted hover:text-fg'"
                        @click="designer.showSide(value)"
                    >
                        {{ t(`designer.sides.${value}`) }}
                    </button>
                </div>
                <div class="inline-flex items-center rounded-lg border border-line bg-surface">
                    <button class="p-2 text-muted hover:text-fg disabled:opacity-40" :disabled="zoom === 0" :title="t('designer.zoomOut')" @click="zoom--"><ZoomOut class="size-4" /></button>
                    <span class="w-12 text-center text-xs text-muted tabular-nums">{{ Math.round((scale / ACTUAL_SIZE) * 100) }}%</span>
                    <button class="p-2 text-muted hover:text-fg disabled:opacity-40" :disabled="zoom === ZOOM_STEPS.length - 1" :title="t('designer.zoomIn')" @click="zoom++"><ZoomIn class="size-4" /></button>
                </div>
                <div v-if="editable" class="inline-flex items-center rounded-lg border border-line bg-surface">
                    <button class="p-2 text-muted hover:text-fg disabled:opacity-40" :disabled="!canUndo" :title="`${t('designer.undo')} (Ctrl+Z)`" data-undo @click="designer.undo()"><Undo2 class="size-4" /></button>
                    <button class="p-2 text-muted hover:text-fg disabled:opacity-40" :disabled="!canRedo" :title="`${t('designer.redo')} (Ctrl+Shift+Z)`" data-redo @click="designer.redo()"><Redo2 class="size-4" /></button>
                </div>
                <button
                    class="inline-flex h-9 items-center rounded-lg border border-line px-2"
                    :class="guides ? 'bg-brand text-brand-contrast' : 'bg-surface text-muted hover:text-fg'"
                    :title="t('designer.guides')"
                    :aria-pressed="guides"
                    data-guides
                    @click="guides = !guides"
                >
                    <Frame class="size-4" />
                </button>
                <label v-if="editable" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-line bg-surface pr-1 pl-2 text-muted" :title="t('designer.snap')">
                    <Grid3x3 class="size-4" />
                    <select v-model.number="snap" class="bg-transparent text-xs text-fg focus:outline-none" data-snap>
                        <option v-for="step in SNAP_STEPS" :key="step" :value="step">{{ step ? `${step} mm` : t('designer.snapOff') }}</option>
                    </select>
                </label>
                <div class="inline-flex items-center rounded-lg border border-line bg-surface">
                    <button v-if="editable" class="p-2 text-muted hover:text-fg disabled:opacity-40" :disabled="transferring" :title="t('designer.startersTitle')" data-starters @click="startersOpen = true"><LayoutTemplate class="size-4" /></button>
                    <button class="p-2 text-muted hover:text-fg disabled:opacity-40" :disabled="transferring" :title="t('designer.export')" data-export @click="exportLayout"><Download class="size-4" /></button>
                    <button v-if="editable" class="p-2 text-muted hover:text-fg disabled:opacity-40" :disabled="transferring" :title="t('designer.import')" data-import @click="importInput?.click()"><Upload class="size-4" /></button>
                    <input ref="importInput" type="file" accept="application/json,.json" class="hidden" data-import-file @change="importFile" />
                </div>
                <template v-if="editable">
                    <span v-if="dirty" class="text-xs text-warning">{{ t('designer.unsaved') }}</span>
                    <UiButton :loading="saving" :disabled="!dirty" data-save @click="save"><Save class="size-4" />{{ t('common.save') }}</UiButton>
                </template>
            </div>
        </div>

        <div v-if="errors[0]" class="flex items-start gap-2 rounded-lg border border-danger/30 bg-danger/5 p-3 text-sm text-danger" role="alert">
            <AlertCircle class="mt-0.5 size-4 shrink-0" />
            <p>{{ errors[0].join(' ') }}</p>
        </div>

        <div class="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)_18rem]">
            <aside class="space-y-2 rounded-xl border border-line bg-surface p-3">
                <template v-if="editable">
                    <h2 class="flex items-center gap-2 text-sm font-medium text-fg"><Plus class="size-4" />{{ t('designer.addElement') }}</h2>
                    <ElementPalette :has-background="hasBackground" @add="add" />
                    <hr class="border-line" />
                </template>
                <h2 class="flex items-center gap-2 text-sm font-medium text-fg"><Layers class="size-4" />{{ t('designer.layers') }}</h2>
                <p v-if="!sideItems.length" class="text-xs text-muted">{{ t('designer.noElements') }}</p>
                <!-- Top layer first, like most design tools -->
                <button
                    v-for="item in [...sideItems].reverse()"
                    :key="item.key"
                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm"
                    :class="selectedKeys.includes(item.key) ? 'bg-brand text-brand-contrast' : 'text-fg hover:bg-surface-2'"
                    @click="designer.select(item.key, $event.shiftKey || $event.ctrlKey || $event.metaKey)"
                >
                    <span class="truncate" :class="item.style.hidden && 'line-through opacity-60'">{{ itemTitle(item) }}</span>
                    <span class="ml-auto flex shrink-0 gap-1 opacity-70">
                        <Lock v-if="item.style.locked" class="size-3.5" />
                        <EyeOff v-if="item.style.hidden" class="size-3.5" />
                    </span>
                    <AlertCircle v-if="errors[item.key]" class="size-4 shrink-0" :class="item.key === selectedKey ? '' : 'text-danger'" />
                </button>
            </aside>

            <section ref="stage" class="relative order-first flex flex-col min-h-[24rem] overflow-auto rounded-xl border border-line bg-surface-2 p-6 lg:order-none">
                <PreviewPicker
                    v-if="auth.can('cardholders.view')"
                    class="-mt-2 mb-4"
                    :template-id="template.id"
                    :for-family="template.audience === 'family_member'"
                    @change="preview = $event"
                />
                <DesignerCanvas
                    class="m-auto"
                    :width="size.width"
                    :height="size.height"
                    :scale="scale"
                    :items="sideItems"
                    :selected-key="selectedKey"
                    :selected-keys="selectedKeys"
                    :value-of="valueOf"
                    :image-of="imageOf"
                    :editable="editable"
                    :invalid-keys="invalidKeys"
                    :snap="snap"
                    :guides="guides"
                    @select="(key, additive) => designer.select(key, additive)"
                    @select-many="designer.selectMany"
                    @move-group="designer.moveBy"
                    @rect="designer.setRect"
                />
                <p v-if="editable" class="absolute right-3 bottom-2 hidden text-[11px] text-muted lg:block">{{ t('designer.shortcuts') }}</p>
            </section>

            <aside class="space-y-4 rounded-xl border border-line bg-surface p-4 text-sm">
                <template v-if="editable && selection.length > 1">
                    <div class="flex items-center justify-between">
                        <p class="font-medium text-fg" data-selection-count>{{ t('designer.selectedCount', { n: selection.length }) }}</p>
                        <UiButton variant="secondary" size="sm" class="text-danger" @click="remove">{{ t('common.delete') }}</UiButton>
                    </div>
                    <AlignTools :count="selection.length" @align="designer.align(selectedKeys, $event)" @distribute="designer.distribute(selectedKeys, $event)" />
                    <p class="text-xs text-muted">{{ t('designer.multiHint') }}</p>
                </template>
                <AlignTools
                    v-else-if="editable && selected && selected.type !== 'background' && !selected.style.locked"
                    :count="1"
                    @align="designer.align([selected.key], $event)"
                    @distribute="() => {}"
                />
                <ElementSettings
                    v-if="selected && editable && catalog && selection.length <= 1"
                    :key="selected.key"
                    :item="selected"
                    :catalog="catalog"
                    :template-id="template.id"
                    :authority-type="template.authority_type?.name ?? null"
                    :field-label="fieldLabel"
                    :errors="errors[selected.key] ?? []"
                    @rect="designer.setRect(selected.key, $event)"
                    @remove="remove"
                    :double-sided="template.is_double_sided"
                    @flag="designer.toggleFlag(selected.key, $event)"
                    @other-side="copyToOtherSide(selected.key)"
                    @duplicate="designer.duplicate(selected.key)"
                    @layer="designer.moveLayer(selected.key, $event)"
                />
                <template v-else-if="selected && selection.length <= 1">
                    <p class="font-medium text-fg">{{ itemTitle(selected) }}</p>
                    <p class="mt-1 text-xs text-muted">{{ t('designer.position', { x: selected.x, y: selected.y, width: selected.width, height: selected.height }) }}</p>
                </template>
                <p v-else-if="!selected" class="text-muted">{{ t('designer.selectHint') }}</p>
            </aside>
        </div>
        <StarterPicker v-model:open="startersOpen" @apply="applyLayout" />
    </div>
    <p v-else-if="loading" class="py-16 text-center text-sm text-muted">{{ t('common.loading') }}</p>
</template>
