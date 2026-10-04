<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlignCenter, AlignLeft, AlignRight, ArrowDown, ArrowDownToLine, ArrowUp, ArrowUpToLine, Bold, Copy, ImagePlus, Italic, Loader2, Plus, Trash2, Underline, X } from 'lucide-vue-next';
import FieldSelect from './FieldSelect.vue';
import type { DesignerItem, LayerMove, Rect } from './useDesigner';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import type { DesignElementStyle, DesignerCatalog } from '@/types';

/**
 * Settings of the selected element (legacy section form): data field, label, text, font, colours,
 * alignment, QR lines and images, plus the exact position and size in millimetres.
 * The item is the designer's own reactive state and is edited in place.
 */
const props = defineProps<{
    item: DesignerItem;
    catalog: DesignerCatalog;
    templateId: string;
    authorityType: string | null;
    fieldLabel: (field: string) => string;
    errors: string[];
}>();

const emit = defineEmits<{ rect: [rect: Rect]; remove: []; duplicate: []; layer: [move: LayerMove] }>();
const { t } = useI18n();
const ui = useUiStore();

const isText = computed(() => props.item.type === 'field' || props.item.type === 'text');
const hasImage = computed(() => props.catalog.types[props.item.type]?.image === true);
const fileInput = useTemplateRef<HTMLInputElement>('file');
const uploading = ref(false);

const inputClass = 'h-8 w-full rounded-md border border-line bg-surface px-2 text-sm text-fg focus:border-brand focus:ring-2 focus:ring-[var(--ring)] focus:outline-none';

/** Apply a typed position or size; the designer keeps it on the card, so show the value it settled on. */
async function setGeometry(key: keyof Rect, event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const number = Number.parseFloat(input.value);
    if (Number.isFinite(number)) {
        emit('rect', { x: props.item.x, y: props.item.y, width: props.item.width, height: props.item.height, [key]: number });
    }
    await nextTick();
    input.value = String(props.item[key]);
}

function setStyle<K extends keyof DesignElementStyle>(key: K, value: DesignElementStyle[K] | undefined): void {
    if (value === undefined || value === '' || value === false) {
        delete props.item.style[key];
    } else {
        props.item.style[key] = value;
    }
}

function toggle(key: 'bold' | 'italic' | 'underline'): void {
    setStyle(key, !props.item.style[key]);
}

function addQrLine(): void {
    props.item.qr_lines = [...(props.item.qr_lines ?? []), { field: 'full_name' }];
}

function setQrMode(index: number, mode: string): void {
    props.item.qr_lines![index] = mode === 'text' ? { text: '' } : { field: 'full_name' };
}

async function upload(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) {
        return;
    }
    uploading.value = true;
    try {
        const body = new FormData();
        body.append('file', file);
        const { data } = await http.post(`/card-templates/${props.templateId}/images`, body);
        props.item.image = data.data.image;
        props.item.image_url = data.data.image_url;
    } catch (error) {
        const apiError = toApiError(error);
        ui.notify(apiError.errors.file?.[0] ?? apiError.message, 'error');
    } finally {
        uploading.value = false;
        if (fileInput.value) {
            fileInput.value.value = '';
        }
    }
}

function removeImage(): void {
    props.item.image = null;
    props.item.image_url = null;
}
</script>

<template>
    <div class="space-y-4 text-sm">
        <div class="space-y-2">
            <div class="flex items-start justify-between gap-2">
                <p class="font-medium text-fg">{{ t(`designer.types.${item.type}`) }}</p>
                <button class="rounded-md p-1.5 text-muted hover:bg-danger/10 hover:text-danger" :title="`${t('common.delete')} (Del)`" data-remove @click="emit('remove')">
                    <Trash2 class="size-4" />
                </button>
            </div>
            <div v-if="item.type !== 'background'" class="flex flex-wrap gap-0.5">
                <button class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-fg" :title="`${t('designer.duplicate')} (Ctrl+D)`" data-duplicate @click="emit('duplicate')"><Copy class="size-4" /></button>
                <span class="mx-1 h-5 w-px self-center bg-line" />
                <button
                    v-for="layer in [{ move: 'top', icon: ArrowUpToLine }, { move: 'up', icon: ArrowUp }, { move: 'down', icon: ArrowDown }, { move: 'bottom', icon: ArrowDownToLine }] as const"
                    :key="layer.move"
                    class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-fg"
                    :title="t(`designer.layer.${layer.move}`)"
                    :data-layer="layer.move"
                    @click="emit('layer', layer.move)"
                >
                    <component :is="layer.icon" class="size-4" />
                </button>
            </div>
        </div>

        <ul v-if="errors.length" class="space-y-1 rounded-md bg-danger/5 p-2 text-xs text-danger" role="alert">
            <li v-for="message in errors" :key="message">{{ message }}</li>
        </ul>

        <!-- Content -->
        <label v-if="item.type === 'field'" class="block space-y-1">
            <span class="text-xs font-medium text-muted">{{ t('designer.field') }}</span>
            <FieldSelect v-model="item.field" :catalog="catalog" :field-label="fieldLabel" data-setting="field" />
        </label>
        <label v-if="item.type === 'text'" class="block space-y-1">
            <span class="text-xs font-medium text-muted">{{ t('designer.content') }}</span>
            <textarea v-model="item.content" rows="3" maxlength="500" :class="[inputClass, 'h-auto py-1.5']" data-setting="content" />
        </label>
        <label v-if="isText" class="block space-y-1">
            <span class="text-xs font-medium text-muted">{{ t('designer.label') }}</span>
            <input v-model="item.label" type="text" maxlength="100" :placeholder="t('designer.labelHint')" :class="inputClass" data-setting="label" />
        </label>

        <!-- QR code lines (legacy qrcode_info) -->
        <div v-if="item.type === 'qr_code'" class="space-y-2">
            <p class="text-xs font-medium text-muted">{{ t('designer.qrLines') }}</p>
            <div v-for="(line, index) in item.qr_lines ?? []" :key="index" class="space-y-1.5 rounded-md border border-line p-2" data-qr-line>
                <div class="flex gap-1.5">
                    <select :value="line.text !== undefined ? 'text' : 'field'" :class="inputClass" @change="setQrMode(index, ($event.target as HTMLSelectElement).value)">
                        <option value="field">{{ t('designer.qrField') }}</option>
                        <option value="text">{{ t('designer.qrText') }}</option>
                    </select>
                    <button class="shrink-0 rounded-md p-1.5 text-muted hover:bg-danger/10 hover:text-danger" :title="t('common.delete')" @click="item.qr_lines!.splice(index, 1)"><X class="size-4" /></button>
                </div>
                <template v-if="line.text === undefined">
                    <FieldSelect v-model="line.field" :catalog="catalog" :field-label="fieldLabel" />
                    <input v-model="line.label" type="text" maxlength="50" :placeholder="t('designer.qrLabel')" :class="inputClass" />
                </template>
                <input v-else v-model="line.text" type="text" maxlength="200" :placeholder="t('designer.qrText')" :class="inputClass" />
            </div>
            <button v-if="(item.qr_lines?.length ?? 0) < 20" class="inline-flex items-center gap-1 text-xs font-medium text-brand hover:underline" data-add-qr-line @click="addQrLine">
                <Plus class="size-3.5" />{{ t('designer.addQrLine') }}
            </button>
            <p class="text-xs text-muted">{{ t('designer.qrHint') }}</p>
        </div>

        <!-- Images (logos and backgrounds) -->
        <div v-if="hasImage" class="space-y-2">
            <div class="flex h-24 items-center justify-center overflow-hidden rounded-md border border-dashed border-line bg-surface-2">
                <img v-if="item.image_url" :src="item.image_url" alt="" class="max-h-full max-w-full object-contain" />
                <ImagePlus v-else class="size-6 text-muted" />
            </div>
            <div class="flex gap-2">
                <button class="inline-flex h-8 items-center gap-1.5 rounded-md border border-line px-3 text-sm hover:bg-surface-2" :disabled="uploading" @click="fileInput?.click()">
                    <Loader2 v-if="uploading" class="size-4 animate-spin" /><ImagePlus v-else class="size-4" />{{ item.image ? t('designer.replaceImage') : t('designer.uploadImage') }}
                </button>
                <button v-if="item.image" class="inline-flex h-8 items-center rounded-md px-2 text-sm text-muted hover:text-danger" @click="removeImage">{{ t('designer.removeImage') }}</button>
            </div>
            <input ref="file" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" data-upload @change="upload" />
            <p class="text-xs text-muted">{{ t(item.type === 'background' ? 'designer.backgroundHint' : 'designer.logoHint') }}</p>
        </div>

        <p v-if="item.type === 'photo'" class="text-xs text-muted">{{ t('designer.photoHint') }}</p>
        <p v-if="item.type === 'cardholder_signature'" class="text-xs text-muted">{{ t('designer.cardholderSignatureHint') }}</p>
        <p v-if="item.type === 'signatory_signature'" class="text-xs" :class="authorityType ? 'text-muted' : 'text-warning'">
            {{ authorityType ? t('designer.signatoryHint', { authority: authorityType }) : t('designer.signatoryMissing') }}
        </p>

        <!-- Text style -->
        <div v-if="isText" class="space-y-3 border-t border-line pt-3">
            <div class="grid grid-cols-[1fr_5rem] gap-2">
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-muted">{{ t('designer.font') }}</span>
                    <select :value="item.style.font_family ?? ''" :class="inputClass" data-setting="font_family" @change="setStyle('font_family', ($event.target as HTMLSelectElement).value)">
                        <option value="">{{ t('designer.defaultFont') }}</option>
                        <option v-for="font in catalog.fonts" :key="font" :value="font" :style="{ fontFamily: font }">{{ font }}</option>
                    </select>
                </label>
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-muted">{{ t('designer.fontSize') }}</span>
                    <input
                        :value="item.style.font_size ?? 8"
                        type="number"
                        min="1"
                        max="72"
                        step="0.5"
                        :class="inputClass"
                        data-setting="font_size"
                        @change="setStyle('font_size', Math.min(72, Math.max(1, Number(($event.target as HTMLInputElement).value) || 8)))"
                    />
                </label>
            </div>

            <div class="flex flex-wrap items-center gap-1">
                <button
                    v-for="flag in [{ key: 'bold', icon: Bold }, { key: 'italic', icon: Italic }, { key: 'underline', icon: Underline }] as const"
                    :key="flag.key"
                    class="rounded-md p-1.5"
                    :class="item.style[flag.key] ? 'bg-brand text-brand-contrast' : 'text-muted hover:bg-surface-2 hover:text-fg'"
                    :title="t(`designer.${flag.key}`)"
                    :aria-pressed="!!item.style[flag.key]"
                    :data-toggle="flag.key"
                    @click="toggle(flag.key)"
                >
                    <component :is="flag.icon" class="size-4" />
                </button>
                <span class="mx-1 h-5 w-px bg-line" />
                <button
                    v-for="align in [{ key: 'left', icon: AlignLeft }, { key: 'center', icon: AlignCenter }, { key: 'right', icon: AlignRight }] as const"
                    :key="align.key"
                    class="rounded-md p-1.5"
                    :class="(item.style.align ?? 'left') === align.key ? 'bg-brand text-brand-contrast' : 'text-muted hover:bg-surface-2 hover:text-fg'"
                    :title="t(`designer.align.${align.key}`)"
                    :aria-pressed="(item.style.align ?? 'left') === align.key"
                    :data-align="align.key"
                    @click="setStyle('align', align.key === 'left' ? undefined : align.key)"
                >
                    <component :is="align.icon" class="size-4" />
                </button>
            </div>

            <div class="grid grid-cols-3 gap-2">
                <label v-for="colour in ['color', 'label_color', 'background'] as const" :key="colour" class="block space-y-1">
                    <span class="block truncate text-xs font-medium text-muted">{{ t(`designer.colours.${colour}`) }}</span>
                    <span class="flex items-center gap-1">
                        <input
                            type="color"
                            :value="item.style[colour] ?? (colour === 'background' ? '#ffffff' : '#111111')"
                            class="h-8 w-full cursor-pointer rounded-md border border-line bg-surface p-0.5"
                            :class="!item.style[colour] && 'opacity-50'"
                            :data-colour="colour"
                            @input="setStyle(colour, ($event.target as HTMLInputElement).value)"
                        />
                        <button v-if="item.style[colour]" class="text-muted hover:text-fg" :title="t('designer.clearColour')" @click="setStyle(colour, undefined)"><X class="size-3.5" /></button>
                    </span>
                </label>
            </div>
        </div>

        <!-- Position and size (mm) -->
        <div v-if="item.type !== 'background'" class="grid grid-cols-2 gap-2 border-t border-line pt-3">
            <label v-for="key in ['x', 'y', 'width', 'height'] as const" :key="key" class="block space-y-1">
                <span class="text-xs font-medium text-muted">{{ t(`designer.geometry.${key}`) }}</span>
                <input :value="item[key]" type="number" step="0.1" min="0" :class="inputClass" :data-geometry="key" @change="setGeometry(key, $event)" />
            </label>
            <p class="col-span-2 text-xs text-muted">{{ t('designer.mmHint') }}</p>
        </div>
    </div>
</template>
