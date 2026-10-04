<script setup lang="ts">
import { computed } from 'vue';
import DesignerCanvas from './DesignerCanvas.vue';
import { sampleValue } from './sample';
import type { DesignerItem } from './useDesigner';
import type { CardTemplate } from '@/types';

/**
 * Read-only front side of a template with sample data, drawn by the designer canvas so it always
 * matches the design.
 */
const props = defineProps<{ template: CardTemplate; box: number }>();

const size = computed(() => {
    const factor = props.template.size_unit === 'px' ? 25.4 / 96 : 1;

    return { width: props.template.width * factor, height: props.template.height * factor };
});
const scale = computed(() => props.box / Math.max(size.value.width, size.value.height));
const items = computed<DesignerItem[]>(() => (props.template.elements ?? []).filter((element) => element.side === 'front').map((element, index) => ({ ...element, key: index + 1 })));

function valueOf(item: DesignerItem): string {
    return sampleValue(item.field);
}
</script>

<template>
    <div class="pointer-events-none" aria-hidden="true">
        <DesignerCanvas :width="size.width" :height="size.height" :scale="scale" :items="items" :selected-key="null" :value-of="valueOf" :editable="false" bare />
    </div>
</template>
