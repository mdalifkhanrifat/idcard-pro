import { computed, ref } from 'vue';
import { http } from '@/lib/http';
import type { CardSide, CardTemplate, DesignElement, DesignerCatalog } from '@/types';

/** A designer element with a local key; server ids change on every save. */
export interface DesignerItem extends DesignElement {
    key: number;
}

let nextKey = 1;

function toItem(element: DesignElement): DesignerItem {
    return { ...element, style: { ...element.style }, qr_lines: element.qr_lines ? element.qr_lines.map((line) => ({ ...line })) : null, key: nextKey++ };
}

/**
 * State of the template designer. The order of `items` within a side is the layer order (first = bottom),
 * which is also the order the API stores.
 */
export function useDesigner() {
    const template = ref<CardTemplate | null>(null);
    const catalog = ref<DesignerCatalog | null>(null);
    const items = ref<DesignerItem[]>([]);
    const side = ref<CardSide>('front');
    const selectedKey = ref<number | null>(null);
    const loading = ref(true);

    const size = computed(() => catalog.value?.size_mm ?? { width: 85.6, height: 54 });
    const sideItems = computed(() => items.value.filter((item) => item.side === side.value));
    const background = computed(() => sideItems.value.find((item) => item.type === 'background') ?? null);
    const selected = computed(() => items.value.find((item) => item.key === selectedKey.value) ?? null);

    async function load(id: string): Promise<void> {
        loading.value = true;
        try {
            const [templateResponse, elementsResponse] = await Promise.all([http.get(`/card-templates/${id}`), http.get(`/card-templates/${id}/elements`)]);
            template.value = templateResponse.data.data;
            catalog.value = elementsResponse.data.meta.catalog;
            items.value = (elementsResponse.data.data as DesignElement[]).map(toItem);
            selectedKey.value = null;
        } finally {
            loading.value = false;
        }
    }

    function showSide(value: CardSide): void {
        side.value = value;
        selectedKey.value = null;
    }

    return { template, catalog, items, side, selectedKey, loading, size, sideItems, background, selected, load, showSide };
}

export type Designer = ReturnType<typeof useDesigner>;
