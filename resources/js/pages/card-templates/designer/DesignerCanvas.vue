<script setup lang="ts">
import { computed } from 'vue';
import DesignerElement from './DesignerElement.vue';
import type { DesignerItem } from './useDesigner';

/**
 * One card side drawn at `scale` pixels per millimetre. The background element fills the side
 * (legacy bg_image/back_bg_image); every other element is placed by its mm position.
 */
const props = defineProps<{
    width: number;
    height: number;
    scale: number;
    items: DesignerItem[];
    selectedKey: number | null;
    valueOf: (item: DesignerItem) => string;
}>();

const emit = defineEmits<{ select: [key: number | null] }>();

const background = computed(() => props.items.find((item) => item.type === 'background') ?? null);
const layers = computed(() => props.items.filter((item) => item.type !== 'background'));

function box(item: DesignerItem): Record<string, string> {
    return {
        left: `${item.x * props.scale}px`,
        top: `${item.y * props.scale}px`,
        width: `${item.width * props.scale}px`,
        height: `${item.height * props.scale}px`,
    };
}
</script>

<template>
    <div
        class="relative shrink-0 overflow-hidden rounded-[3%] bg-white shadow-lg ring-1 ring-black/10 select-none"
        :style="{
            width: `${width * scale}px`,
            height: `${height * scale}px`,
            backgroundImage: background?.image_url ? `url('${background.image_url}')` : undefined,
            backgroundSize: 'cover',
            backgroundPosition: 'center',
        }"
        @pointerdown.self="emit('select', null)"
    >
        <div
            v-for="item in layers"
            :key="item.key"
            class="absolute cursor-move outline-1 outline-slate-400/60 outline-dashed"
            :class="item.key === selectedKey && 'outline-2 outline-[var(--brand)] outline-solid'"
            :style="box(item)"
            @pointerdown.stop="emit('select', item.key)"
        >
            <DesignerElement :element="item" :scale="scale" :value="valueOf(item)" />
        </div>
    </div>
</template>
