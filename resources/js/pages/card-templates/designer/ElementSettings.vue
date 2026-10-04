<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    AlignVerticalJustifyCenter,
    AlignVerticalJustifyEnd,
    AlignVerticalJustifyStart,
    ArrowDown,
    ArrowDownToLine,
    ArrowUp,
    ArrowUpToLine,
    Bold,
    CaseUpper,
    Circle,
    CircleUser,
    Copy,
    ImagePlus,
    Italic,
    Loader2,
    Plus,
    Square,
    Trash2,
    Underline,
    X,
} from 'lucide-vue-next';
import ColorInput from './ColorInput.vue';
import FieldSelect from './FieldSelect.vue';
import { imageFit } from './styles';
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
const isImage = computed(() => ['logo', 'background', 'photo', 'signatory_signature', 'cardholder_signature'].includes(props.item.type));

const GRADIENT_DIRECTIONS = ['to bottom', 'to right', 'to bottom right', 'to top right'] as const;

/** Text spacing settings: line height (×), letter spacing (pt) and padding (mm). */
const TEXT_NUMBERS = [
    { key: 'line_height', min: 0.5, max: 3, step: 0.1, empty: 1.2 },
    { key: 'letter_spacing', min: -2, max: 20, step: 0.1, empty: 0 },
    { key: 'padding', min: 0, max: 20, step: 0.1, empty: 0 },
] as const;
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

function toggle(key: 'bold' | 'italic' | 'underline' | 'uppercase'): void {
    setStyle(key, !props.item.style[key]);
}

type NumberKey = 'font_size' | 'line_height' | 'letter_spacing' | 'padding' | 'border_width' | 'border_radius';

/** Numeric style values are kept within the range the server accepts; 0 removes optional spacing. */
function setNumber(key: NumberKey, event: Event, min: number, max: number): void {
    const input = event.target as HTMLInputElement;
    const value = Number.parseFloat(input.value);
    if (!Number.isFinite(value)) {
        setStyle(key, undefined);

        return;
    }
    const clamped = Math.min(max, Math.max(min, value));
    input.value = String(clamped);
    setStyle(key, clamped === 0 ? undefined : clamped);
}

function setOpacity(event: Event): void {
    const value = Number((event.target as HTMLInputElement).value) / 100;
    setStyle('opacity', value >= 1 ? undefined : value);
}

/** Without a fill colour there is nothing to fade into, so the gradient goes too. */
function setFill(colour: string | undefined): void {
    setStyle('background', colour);
    if (!colour) {
        setStyle('gradient_to', undefined);
        setStyle('gradient_direction', undefined);
    }
}

/** Round photo: corners of half the shorter side. */
function makeRound(): void {
    setStyle('border_radius', Math.round((Math.min(props.item.width, props.item.height) / 2) * 10) / 10);
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

        <!-- Shape -->
        <div v-if="item.type === 'shape'" class="space-y-1">
            <span class="text-xs font-medium text-muted">{{ t('designer.shapeKind') }}</span>
            <div class="flex gap-1">
                <button
                    v-for="kind in [{ key: 'rectangle', icon: Square }, { key: 'ellipse', icon: Circle }] as const"
                    :key="kind.key"
                    class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-md border border-line py-1.5 text-xs"
                    :class="(item.style.shape ?? 'rectangle') === kind.key ? 'bg-brand text-brand-contrast' : 'text-fg hover:bg-surface-2'"
                    :data-shape="kind.key"
                    @click="setStyle('shape', kind.key === 'rectangle' ? undefined : kind.key)"
                >
                    <component :is="kind.icon" class="size-3.5" />{{ t(`designer.shapes.${kind.key}`) }}
                </button>
            </div>
        </div>

        <!-- How an image fills its box -->
        <div v-if="isImage" class="space-y-1">
            <span class="text-xs font-medium text-muted">{{ t('designer.fit') }}</span>
            <div class="flex gap-1">
                <button
                    v-for="fit in ['cover', 'contain'] as const"
                    :key="fit"
                    class="flex-1 rounded-md border border-line py-1.5 text-xs"
                    :class="imageFit(item) === fit ? 'bg-brand text-brand-contrast' : 'text-fg hover:bg-surface-2'"
                    :data-fit="fit"
                    @click="setStyle('fit', fit)"
                >
                    {{ t(`designer.fits.${fit}`) }}
                </button>
                <button v-if="item.type === 'photo'" class="inline-flex items-center gap-1 rounded-md border border-line px-2 text-xs text-fg hover:bg-surface-2" :title="t('designer.makeRound')" data-round @click="makeRound">
                    <CircleUser class="size-3.5" />{{ t('designer.round') }}
                </button>
            </div>
        </div>

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
                    <input :value="item.style.font_size ?? 8" type="number" min="1" max="72" step="0.5" :class="inputClass" data-setting="font_size" @change="setNumber('font_size', $event, 1, 72)" />
                </label>
            </div>

            <div class="flex flex-wrap items-center gap-1">
                <button
                    v-for="flag in [{ key: 'bold', icon: Bold }, { key: 'italic', icon: Italic }, { key: 'underline', icon: Underline }, { key: 'uppercase', icon: CaseUpper }] as const"
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
            </div>
            <div class="flex flex-wrap items-center gap-1">
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
                <span class="mx-1 h-5 w-px bg-line" />
                <button
                    v-for="vertical in [{ key: 'top', icon: AlignVerticalJustifyStart }, { key: 'middle', icon: AlignVerticalJustifyCenter }, { key: 'bottom', icon: AlignVerticalJustifyEnd }] as const"
                    :key="vertical.key"
                    class="rounded-md p-1.5"
                    :class="(item.style.vertical_align ?? 'top') === vertical.key ? 'bg-brand text-brand-contrast' : 'text-muted hover:bg-surface-2 hover:text-fg'"
                    :title="t(`designer.vertical.${vertical.key}`)"
                    :data-vertical="vertical.key"
                    @click="setStyle('vertical_align', vertical.key === 'top' ? undefined : vertical.key)"
                >
                    <component :is="vertical.icon" class="size-4" />
                </button>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <ColorInput :model-value="item.style.color" :label="t('designer.colours.color')" placeholder="#111111" name="color" @update:model-value="setStyle('color', $event)" />
                <ColorInput :model-value="item.style.label_color" :label="t('designer.colours.label_color')" placeholder="#111111" name="label_color" @update:model-value="setStyle('label_color', $event)" />
            </div>
            <div class="grid grid-cols-3 gap-2">
                <label v-for="number in TEXT_NUMBERS" :key="number.key" class="block space-y-1">
                    <span class="block truncate text-xs font-medium text-muted">{{ t(`designer.textNumbers.${number.key}`) }}</span>
                    <input
                        :value="item.style[number.key] ?? number.empty"
                        type="number"
                        :min="number.min"
                        :max="number.max"
                        :step="number.step"
                        :class="inputClass"
                        :data-setting="number.key"
                        @change="setNumber(number.key, $event, number.min, number.max)"
                    />
                </label>
            </div>
        </div>

        <!-- Fill and border (legacy bg_color, plus gradients, borders, rounded corners and opacity) -->
        <div class="space-y-3 border-t border-line pt-3">
            <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ t(item.type === 'background' ? 'designer.sideColour' : 'designer.fillAndBorder') }}</p>
            <div class="grid grid-cols-2 gap-2">
                <ColorInput :model-value="item.style.background" :label="t('designer.fill')" name="background" @update:model-value="setFill($event)" />
                <ColorInput v-if="item.style.background" :model-value="item.style.gradient_to" :label="t('designer.gradientTo')" name="gradient_to" @update:model-value="setStyle('gradient_to', $event)" />
            </div>
            <label v-if="item.style.gradient_to" class="block space-y-1">
                <span class="text-xs font-medium text-muted">{{ t('designer.gradientDirection') }}</span>
                <select :value="item.style.gradient_direction ?? 'to bottom'" :class="inputClass" data-setting="gradient_direction" @change="setStyle('gradient_direction', ($event.target as HTMLSelectElement).value as DesignElementStyle['gradient_direction'])">
                    <option v-for="direction in GRADIENT_DIRECTIONS" :key="direction" :value="direction">{{ t(`designer.gradientDirections.${direction.replaceAll(' ', '_')}`) }}</option>
                </select>
            </label>
            <template v-if="item.type !== 'background'">
                <div class="grid grid-cols-3 gap-2">
                    <ColorInput :model-value="item.style.border_color" :label="t('designer.borderColour')" placeholder="#111111" name="border_color" @update:model-value="setStyle('border_color', $event)" />
                    <label class="block space-y-1">
                        <span class="block truncate text-xs font-medium text-muted">{{ t('designer.borderWidth') }}</span>
                        <input :value="item.style.border_width ?? 0" type="number" min="0" max="10" step="0.1" :class="inputClass" data-setting="border_width" @change="setNumber('border_width', $event, 0, 10)" />
                    </label>
                    <label class="block space-y-1">
                        <span class="block truncate text-xs font-medium text-muted">{{ t('designer.radius') }}</span>
                        <input :value="item.style.border_radius ?? 0" type="number" min="0" max="100" step="0.5" :class="inputClass" data-setting="border_radius" @change="setNumber('border_radius', $event, 0, 100)" />
                    </label>
                </div>
                <label class="block space-y-1">
                    <span class="flex justify-between text-xs font-medium text-muted">
                        {{ t('designer.opacity') }}<span class="tabular-nums">{{ Math.round((item.style.opacity ?? 1) * 100) }}%</span>
                    </span>
                    <input :value="(item.style.opacity ?? 1) * 100" type="range" min="5" max="100" step="5" class="w-full accent-[var(--brand)]" data-setting="opacity" @input="setOpacity($event)" />
                </label>
            </template>
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
