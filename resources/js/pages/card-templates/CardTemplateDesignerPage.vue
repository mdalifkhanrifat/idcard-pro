<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import { onBeforeRouteLeave, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { AlertCircle, ArrowLeft, Layers, Plus, Save, Trash2, ZoomIn, ZoomOut } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import DesignerCanvas from './designer/DesignerCanvas.vue';
import ElementPalette from './designer/ElementPalette.vue';
import { sampleValue } from './designer/sample';
import { useDesigner, type DesignerItem } from './designer/useDesigner';
import { useConfirm } from '@/composables/useConfirm';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { CardSide, DesignElementType } from '@/types';

const { t, te } = useI18n();
const route = useRoute();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();
const designer = useDesigner();
const { template, catalog, items, side, sideItems, selected, selectedKey, loading, saving, errors, dirty, size } = designer;

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

/** Largest zoom at which the whole card fits the canvas area. */
function fitZoom(): void {
    const room = (stage.value?.clientWidth ?? 600) - 48;
    let fitting = 0;
    ZOOM_STEPS.forEach((step, index) => {
        if (step * size.value.width <= room) {
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

function add(type: DesignElementType): void {
    designer.add(type, t('designer.defaultText'));
}

function remove(): void {
    items.value = items.value.filter((item) => item.key !== selectedKey.value);
    selectedKey.value = null;
}

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

function valueOf(item: DesignerItem): string {
    return sampleValue(item.field, { 'organization.name': auth.tenant?.name ?? '' });
}

/** Short name for the layer list. */
function itemTitle(item: DesignerItem): string {
    if (item.type === 'field') {
        return fieldLabel(item.field) || t('designer.types.field');
    }
    if (item.type === 'text') {
        return item.content || t('designer.types.text');
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
                    :class="item.key === selectedKey ? 'bg-brand/10 text-brand' : 'text-fg hover:bg-surface-2'"
                    @click="selectedKey = item.key"
                >
                    <span class="truncate">{{ itemTitle(item) }}</span>
                    <AlertCircle v-if="errors[item.key]" class="ml-auto size-4 shrink-0 text-danger" />
                </button>
            </aside>

            <section ref="stage" class="order-first flex min-h-[24rem] overflow-auto rounded-xl border border-line bg-surface-2 p-6 lg:order-none">
                <DesignerCanvas
                    class="m-auto"
                    :width="size.width"
                    :height="size.height"
                    :scale="scale"
                    :items="sideItems"
                    :selected-key="selectedKey"
                    :value-of="valueOf"
                    :editable="editable"
                    :invalid-keys="invalidKeys"
                    @select="selectedKey = $event"
                    @rect="designer.setRect"
                />
            </section>

            <aside class="rounded-xl border border-line bg-surface p-4 text-sm">
                <template v-if="selected">
                    <p class="font-medium text-fg">{{ itemTitle(selected) }}</p>
                    <p class="mt-1 text-xs text-muted">{{ t('designer.position', { x: selected.x, y: selected.y, width: selected.width, height: selected.height }) }}</p>
                    <ul v-if="errors[selected.key]" class="mt-3 space-y-1 text-xs text-danger" role="alert">
                        <li v-for="message in errors[selected.key]" :key="message">{{ message }}</li>
                    </ul>
                    <UiButton v-if="editable" variant="secondary" size="sm" class="mt-4 text-danger" data-remove @click="remove"><Trash2 class="size-4" />{{ t('common.delete') }}</UiButton>
                </template>
                <p v-else class="text-muted">{{ t('designer.selectHint') }}</p>
            </aside>
        </div>
    </div>
    <p v-else-if="loading" class="py-16 text-center text-sm text-muted">{{ t('common.loading') }}</p>
</template>
