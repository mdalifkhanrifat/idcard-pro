<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import DesignerCanvas from '@/pages/card-templates/designer/DesignerCanvas.vue';
import type { DesignerItem } from '@/pages/card-templates/designer/useDesigner';
import { ensureTenantFonts } from '@/lib/tenantFonts';
import type { CardLayout, CardSide, CardValues } from '@/types';

/**
 * A card as it prints: its design drawn by the designer canvas (hidden elements left out, fallback images,
 * auto-fit, rotation, barcodes), filled with the card's values. Used for issued cards and the issue preview,
 * and later for printing, so all three always match.
 */
const props = withDefaults(defineProps<{ layout: CardLayout; values: CardValues; /** Pixels of the card's longer side. */ box?: number; sides?: CardSide[] }>(), { box: 320 });
const { t } = useI18n();

const scale = computed(() => props.box / Math.max(props.layout.size_mm.width, props.layout.size_mm.height));
const shownSides = computed<CardSide[]>(() => props.sides ?? (props.layout.is_double_sided ? ['front', 'back'] : ['front']));
const itemsOf = (side: CardSide): DesignerItem[] => props.layout.elements.filter((element) => element.side === side).map((element, index) => ({ ...element, style: { ...element.style }, key: index + 1 }));

function valueOf(item: DesignerItem): string {
    return item.field ? (props.values.fields[item.field] ?? '') : '';
}

function imageOf(item: DesignerItem): string | null {
    return item.type === 'photo' || item.type === 'cardholder_signature' || item.type === 'signatory_signature' ? props.values.images[item.type] : null;
}

// Organization fonts the design may use
onMounted(ensureTenantFonts);
</script>

<template>
    <div class="flex flex-wrap gap-4" data-card-render>
        <figure v-for="side in shownSides" :key="side" class="space-y-1">
            <DesignerCanvas
                :width="layout.size_mm.width"
                :height="layout.size_mm.height"
                :scale="scale"
                :items="itemsOf(side)"
                :selected-key="null"
                :value-of="valueOf"
                :image-of="imageOf"
                :editable="false"
                bare
                blank-missing
                :data-side="side"
            />
            <figcaption v-if="shownSides.length > 1" class="text-center text-xs text-muted">{{ t(`designer.sides.${side}`) }}</figcaption>
        </figure>
    </div>
</template>
