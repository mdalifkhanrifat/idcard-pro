<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ImageIcon, PenLine, QrCode, UserRound } from 'lucide-vue-next';
import type { DesignElement } from '@/types';

/**
 * Draws the content of one element inside its box. Text sizes are in points and scale with the canvas.
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
const PT_TO_MM = 25.4 / 72;

const textStyle = computed(() => {
    const style = props.element.style;

    return {
        fontFamily: style.font_family ? `'${style.font_family}', sans-serif` : undefined,
        fontSize: `${(style.font_size ?? 8) * PT_TO_MM * props.scale}px`,
        color: style.color ?? '#111111',
        backgroundColor: style.background,
        fontWeight: style.bold ? 700 : 400,
        fontStyle: style.italic ? 'italic' : 'normal',
        textDecoration: style.underline ? 'underline' : 'none',
        textAlign: style.align ?? 'left',
    };
});

const labelStyle = computed(() => ({ color: props.element.style.label_color ?? props.element.style.color ?? '#111111' }));
</script>

<template>
    <div v-if="element.type === 'field'" class="size-full overflow-hidden leading-tight break-words" :style="textStyle">
        <template v-if="element.label">
            <span class="whitespace-pre" :style="labelStyle">{{ element.label }}</span>
            <span :style="labelStyle"> : </span>
        </template>
        <span>{{ value }}</span>
    </div>

    <div v-else-if="element.type === 'text'" class="size-full overflow-hidden leading-tight break-words whitespace-pre-line" :style="textStyle">
        <span v-if="element.label" class="whitespace-pre" :style="labelStyle">{{ element.label }} </span>{{ element.content }}
    </div>

    <img v-else-if="element.type === 'logo' && element.image_url" :src="element.image_url" alt="" class="size-full object-contain" draggable="false" />
    <img v-else-if="imageUrl" :src="imageUrl" alt="" class="size-full" :class="element.type === 'photo' ? 'object-cover' : 'object-contain'" draggable="false" />

    <div v-else class="flex size-full items-center justify-center overflow-hidden bg-slate-500/10 text-slate-500" :class="element.type === 'photo' && 'bg-slate-500/20'">
        <UserRound v-if="element.type === 'photo'" class="size-3/5" />
        <QrCode v-else-if="element.type === 'qr_code'" class="size-full" :stroke-width="1.25" />
        <span v-else-if="element.type === 'signatory_signature' || element.type === 'cardholder_signature'" class="flex items-center gap-1 font-serif italic" :style="{ fontSize: `${Math.max(8, scale * 2.5)}px` }">
            <PenLine class="size-[1.1em]" />{{ t('designer.sampleSignature') }}
        </span>
        <ImageIcon v-else class="size-1/2" />
    </div>
</template>
