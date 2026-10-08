<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ImageIcon, PenLine, QrCode, UserRound } from 'lucide-vue-next';
import { QUIET_ZONE_MODULES, encodeBarcode } from './barcode';
import { DEFAULT_QR_ERROR_LEVEL, QR_QUIET_ZONE, encodeQr } from './qr';
import { PT_TO_MM, boxCss, imageFit, rotationCss, textCss } from './styles';
import type { DesignElement } from '@/types';

/**
 * Draws one element inside its box: fill, border and rounded corners first, then its content.
 * Text sizes are in points and scale with the canvas.
 */
const props = defineProps<{
    element: DesignElement;
    /** Screen pixels per millimetre. */
    scale: number;
    /** Text shown for a data field (sample or real). */
    value: string;
    /** Real photo or signature when previewing a cardholder; without one the element's own (fallback) image shows. */
    imageUrl?: string | null;
    /** The card as it prints: problems leave a blank space instead of an error box (they are warned about before printing). */
    bare?: boolean;
}>();

const { t } = useI18n();

const box = computed(() => boxCss(props.element.style, props.scale));
const turned = computed(() => rotationCss(props.element, props.scale));
/*
 * Auto-fit: the largest font (down to 4 pt) at which the text fits its box, found by measuring.
 * Used by the designer, thumbnails and printing alike, so they all shrink the same way.
 */
const MIN_FONT_PT = 4;
const fitFactor = ref(1);
const textBox = useTemplateRef<HTMLElement>('textBox');
const text = computed(() => {
    const css = textCss(props.element.style, props.scale);

    return fitFactor.value < 1 ? { ...css, fontSize: `${(props.element.style.font_size ?? 8) * PT_TO_MM * props.scale * fitFactor.value}px` } : css;
});

function measureFit(): void {
    const box = textBox.value;
    if (!box || !props.element.style.auto_fit) {
        fitFactor.value = 1;

        return;
    }
    const base = (props.element.style.font_size ?? 8) * PT_TO_MM * props.scale;
    const fits = (factor: number): boolean => {
        box.style.fontSize = `${base * factor}px`;

        return box.scrollWidth <= box.clientWidth + 0.5 && box.scrollHeight <= box.clientHeight + 0.5;
    };
    let factor = 1;
    if (!fits(1)) {
        let low = Math.min(1, MIN_FONT_PT / (props.element.style.font_size ?? 8));
        let high = 1;
        for (let step = 0; step < 8; step++) {
            const middle = (low + high) / 2;
            if (fits(middle)) {
                low = middle;
            } else {
                high = middle;
            }
        }
        factor = low;
    }
    box.style.fontSize = `${base * factor}px`;
    fitFactor.value = factor;
}

async function refit(): Promise<void> {
    fitFactor.value = 1;
    await nextTick();
    measureFit();
}

watch(() => [props.value, props.element.content, props.element.label, props.element.width, props.element.height, props.scale, JSON.stringify(props.element.style)], refit);
function refitAfterFonts(): void {
    if (props.element.style.auto_fit) {
        refit();
    }
}

onMounted(() => {
    refit();
    // Measurements change once web fonts finish loading, including organization fonts added later.
    document.fonts?.ready.then(refit);
    document.fonts?.addEventListener('loadingdone', refitAfterFonts);
});
onBeforeUnmount(() => document.fonts?.removeEventListener('loadingdone', refitAfterFonts));
const labelStyle = computed(() => ({ color: props.element.style.label_color ?? props.element.style.color ?? '#111111' }));
const fit = computed(() => (imageFit(props.element) === 'cover' ? 'object-cover' : 'object-contain'));
/** Bars stretch to the box; the value shows under them in the element's font when asked. */
const barcode = computed(() => (props.element.type === 'barcode' ? encodeBarcode(props.value, props.element.style.barcode_format) : null));
/** QR code of the element's lines (the value); square, centred in the box with its quiet zone. */
const qr = computed(() => (props.element.type === 'qr_code' ? encodeQr(props.value, props.element.style.qr_error_correction ?? DEFAULT_QR_ERROR_LEVEL) : null));
const image = computed(() => (props.element.type === 'logo' ? props.element.image_url : (props.imageUrl ?? props.element.image_url)));
</script>

<template>
    <div class="relative size-full overflow-hidden" :style="box">
        <div :class="!turned && 'size-full'" :style="turned ?? undefined">
            <div v-if="element.type === 'field' || element.type === 'text'" ref="textBox" class="flex size-full flex-col overflow-hidden break-words" :style="text">
                <div :class="element.type === 'text' && (element.style.single_line ? 'whitespace-pre' : 'whitespace-pre-line')">
                    <template v-if="element.label">
                        <span class="whitespace-pre" :style="labelStyle">{{ element.label }}</span>
                        <span v-if="element.type === 'field'" :style="labelStyle"> : </span>
                        <span v-else> </span>
                    </template>
                    <span>{{ element.type === 'field' ? value : element.content }}</span>
                </div>
            </div>

            <div v-else-if="element.type === 'barcode'" class="flex size-full flex-col overflow-hidden" :style="text" data-barcode>
                <template v-if="barcode">
                    <svg class="min-h-0 w-full flex-1" :viewBox="`0 0 ${barcode.modules + QUIET_ZONE_MODULES * 2} 1`" preserveAspectRatio="none" shape-rendering="crispEdges" aria-hidden="true">
                        <rect v-for="bar in barcode.bars" :key="bar[0]" :x="bar[0] + QUIET_ZONE_MODULES" y="0" :width="bar[1]" height="1" :fill="element.style.color ?? '#000000'" />
                    </svg>
                    <div v-if="element.style.barcode_text" class="shrink-0 leading-tight whitespace-nowrap">{{ barcode.text }}</div>
                </template>
                <!-- A value the format cannot encode: never print a wrong barcode; in the designer, show why -->
                <div
                    v-else-if="value && !bare"
                    class="flex size-full items-center justify-center bg-danger/10 p-0.5 text-center leading-tight text-danger"
                    :style="{ fontSize: `${Math.max(7, scale * 1.8)}px`, fontFamily: 'sans-serif', fontWeight: 400, fontStyle: 'normal' }"
                    data-barcode-invalid
                >
                    {{ t('designer.barcodeInvalid') }}
                </div>
            </div>

            <div v-else-if="element.type === 'qr_code' && (qr || bare)" class="size-full" data-qr>
                <svg v-if="qr" class="size-full" :viewBox="`${-QR_QUIET_ZONE} ${-QR_QUIET_ZONE} ${qr.size + QR_QUIET_ZONE * 2} ${qr.size + QR_QUIET_ZONE * 2}`" shape-rendering="crispEdges" aria-hidden="true">
                    <path :d="qr.path" :fill="element.style.color ?? '#000000'" />
                </svg>
            </div>
            <!-- Text too long for a QR code: say so in the designer, never print a wrong code -->
            <div
                v-else-if="element.type === 'qr_code' && value"
                class="flex size-full items-center justify-center bg-danger/10 p-0.5 text-center leading-tight text-danger"
                :style="{ fontSize: `${Math.max(7, scale * 1.8)}px`, fontFamily: 'sans-serif', fontWeight: 400, fontStyle: 'normal' }"
                data-qr-invalid
            >
                {{ t('designer.qrTooLong') }}
            </div>

            <template v-else-if="element.type !== 'shape'">
                <img v-if="image" :src="image" alt="" class="size-full" :class="fit" draggable="false" />
                <div v-else class="flex size-full items-center justify-center bg-slate-500/10 text-slate-500" :class="element.type === 'photo' && 'bg-slate-500/20'">
                    <UserRound v-if="element.type === 'photo'" class="size-3/5" />
                    <QrCode v-else-if="element.type === 'qr_code'" class="size-full" :stroke-width="1.25" />
                    <span v-else-if="element.type === 'signatory_signature' || element.type === 'cardholder_signature'" class="flex items-center gap-1 font-serif italic" :style="{ fontSize: `${Math.max(8, scale * 2.5)}px` }">
                        <PenLine class="size-[1.1em]" />{{ t('designer.sampleSignature') }}
                    </span>
                    <ImageIcon v-else class="size-1/2" />
                </div>
            </template>
        </div>
    </div>
</template>
