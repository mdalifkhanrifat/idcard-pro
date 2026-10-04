<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ImageIcon, PenLine, QrCode, UserRound } from 'lucide-vue-next';
import { boxCss, imageFit, textCss } from './styles';
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
    /** Real photo or signature when previewing a cardholder. */
    imageUrl?: string | null;
}>();

const { t } = useI18n();

const box = computed(() => boxCss(props.element.style, props.scale));
const text = computed(() => textCss(props.element.style, props.scale));
const labelStyle = computed(() => ({ color: props.element.style.label_color ?? props.element.style.color ?? '#111111' }));
const fit = computed(() => (imageFit(props.element) === 'cover' ? 'object-cover' : 'object-contain'));
const image = computed(() => (props.element.type === 'logo' ? props.element.image_url : props.imageUrl));
</script>

<template>
    <div class="size-full overflow-hidden" :style="box">
        <div v-if="element.type === 'field' || element.type === 'text'" class="flex size-full flex-col overflow-hidden break-words" :style="text">
            <div :class="element.type === 'text' && 'whitespace-pre-line'">
                <template v-if="element.label">
                    <span class="whitespace-pre" :style="labelStyle">{{ element.label }}</span>
                    <span v-if="element.type === 'field'" :style="labelStyle"> : </span>
                    <span v-else> </span>
                </template>
                <span>{{ element.type === 'field' ? value : element.content }}</span>
            </div>
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
</template>
