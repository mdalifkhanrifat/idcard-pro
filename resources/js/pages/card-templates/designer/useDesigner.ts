import { computed, ref } from 'vue';
import { http, toApiError } from '@/lib/http';
import type { CardSide, CardTemplate, DesignElement, DesignElementType, DesignerCatalog } from '@/types';

/** A designer element with a local key; server ids change on every save. */
export interface DesignerItem extends DesignElement {
    key: number;
}

export interface Rect {
    x: number;
    y: number;
    width: number;
    height: number;
}

/** Smallest element edge, in millimetres. */
export const MIN_SIZE = 1;

/** Default size (mm) and style of new elements; text sizes are in points. */
const DEFAULTS: Record<DesignElementType, Partial<DesignElement> & { width: number; height: number }> = {
    logo: { width: 12, height: 12 },
    background: { width: 0, height: 0 },
    photo: { width: 22, height: 28 },
    signatory_signature: { width: 24, height: 9 },
    cardholder_signature: { width: 24, height: 9 },
    qr_code: { width: 18, height: 18, qr_lines: [{ field: 'employee_no' }] },
    field: { width: 40, height: 5, field: 'full_name', style: { font_family: 'Inter', font_size: 8, color: '#111111' } },
    text: { width: 40, height: 5, style: { font_family: 'Inter', font_size: 8, color: '#111111' } },
};

let nextKey = 1;

function toItem(element: DesignElement, key = nextKey++): DesignerItem {
    return { ...element, style: { ...element.style }, qr_lines: element.qr_lines ? element.qr_lines.map((line) => ({ ...line })) : null, key };
}

/** Round to 0.1 mm, the precision used while editing. */
export function roundMm(value: number): number {
    return Math.round(value * 10) / 10;
}

/** What the API stores for an element. */
function toPayload(item: DesignerItem): Omit<DesignElement, 'id' | 'image_url'> {
    return {
        side: item.side,
        type: item.type,
        field: item.field,
        label: item.label,
        content: item.content,
        qr_lines: item.qr_lines,
        image: item.image,
        x: item.x,
        y: item.y,
        width: item.width,
        height: item.height,
        style: item.style,
    };
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
    const saving = ref(false);
    /** Server validation messages by item key; `0` holds messages that belong to no element. */
    const errors = ref<Record<number, string[]>>({});
    const savedSnapshot = ref('[]');

    const size = computed(() => catalog.value?.size_mm ?? { width: 85.6, height: 54 });
    const sideItems = computed(() => items.value.filter((item) => item.side === side.value));
    const selected = computed(() => items.value.find((item) => item.key === selectedKey.value) ?? null);
    const snapshot = computed(() => JSON.stringify(items.value.map(toPayload)));
    const dirty = computed(() => snapshot.value !== savedSnapshot.value);

    async function load(id: string): Promise<void> {
        loading.value = true;
        try {
            const [templateResponse, elementsResponse] = await Promise.all([http.get(`/card-templates/${id}`), http.get(`/card-templates/${id}/elements`)]);
            template.value = templateResponse.data.data;
            catalog.value = elementsResponse.data.meta.catalog;
            items.value = (elementsResponse.data.data as DesignElement[]).map((element) => toItem(element));
            savedSnapshot.value = snapshot.value;
            selectedKey.value = null;
            errors.value = {};
        } finally {
            loading.value = false;
        }
    }

    function showSide(value: CardSide): void {
        side.value = value;
        selectedKey.value = null;
    }

    /** Keep a rectangle inside the card and at least MIN_SIZE wide and high. */
    function clampRect(rect: Rect): Rect {
        const width = roundMm(Math.min(Math.max(rect.width, MIN_SIZE), size.value.width));
        const height = roundMm(Math.min(Math.max(rect.height, MIN_SIZE), size.value.height));

        return {
            x: roundMm(Math.min(Math.max(rect.x, 0), size.value.width - width)),
            y: roundMm(Math.min(Math.max(rect.y, 0), size.value.height - height)),
            width,
            height,
        };
    }

    /**
     * Add an element in the middle of the current side and select it. A side has one background,
     * so asking for a second one selects the existing one. `text` is the starting content of a text element.
     */
    function add(type: DesignElementType, text = 'Text'): void {
        if (type === 'background') {
            const existing = sideItems.value.find((item) => item.type === 'background');
            if (existing) {
                selectedKey.value = existing.key;

                return;
            }
        }

        const defaults = DEFAULTS[type];
        const rect =
            type === 'background'
                ? { x: 0, y: 0, width: size.value.width, height: size.value.height }
                : clampRect({ x: (size.value.width - defaults.width) / 2, y: (size.value.height - defaults.height) / 2, width: defaults.width, height: defaults.height });
        const item = toItem({
            side: side.value,
            type,
            field: defaults.field ?? null,
            label: null,
            content: type === 'text' ? text : null,
            qr_lines: defaults.qr_lines ? defaults.qr_lines.map((line) => ({ ...line })) : null,
            image: null,
            image_url: null,
            style: { ...(defaults.style ?? {}) },
            ...rect,
        });

        // The background always sits at the bottom of its side.
        if (type === 'background') {
            items.value.unshift(item);
        } else {
            items.value.push(item);
        }
        selectedKey.value = item.key;
    }

    function setRect(key: number, rect: Rect): void {
        const item = items.value.find((candidate) => candidate.key === key);
        if (item) {
            Object.assign(item, clampRect(rect));
        }
    }

    async function save(): Promise<boolean> {
        if (!template.value) {
            return false;
        }
        saving.value = true;
        errors.value = {};
        try {
            const response = await http.put(`/card-templates/${template.value.id}/elements`, { elements: items.value.map(toPayload) });
            adoptSaved(response.data.data as DesignElement[]);
            template.value.version = response.data.meta.version;

            return true;
        } catch (error) {
            const apiError = toApiError(error);
            const byKey: Record<number, string[]> = {};
            for (const [path, messages] of Object.entries(apiError.errors)) {
                const index = /^elements\.(\d+)\./.exec(path)?.[1];
                const key = index !== undefined ? (items.value[Number(index)]?.key ?? 0) : 0;
                byKey[key] = [...(byKey[key] ?? []), ...messages];
            }
            if (!Object.keys(byKey).length) {
                byKey[0] = [apiError.message];
            }
            errors.value = byKey;

            return false;
        } finally {
            saving.value = false;
        }
    }

    /**
     * Take the server's copy (rounded values, new ids and image links) while keeping local keys,
     * so the selection survives a save. The API returns each side bottom layer first, as sent.
     */
    function adoptSaved(saved: DesignElement[]): void {
        const keysBySide: Record<CardSide, number[]> = { front: [], back: [] };
        for (const item of items.value) {
            keysBySide[item.side].push(item.key);
        }
        const used: Record<CardSide, number> = { front: 0, back: 0 };
        const fresh = saved.map((element) => toItem(element, keysBySide[element.side][used[element.side]++] ?? nextKey++));
        // Keep the local order (front/back interleaving does not matter to the API).
        items.value = items.value.map((item) => fresh.find((candidate) => candidate.key === item.key) ?? item);
        savedSnapshot.value = snapshot.value;
    }

    return { template, catalog, items, side, selectedKey, loading, saving, errors, dirty, size, sideItems, selected, load, showSide, add, setRect, clampRect, save };
}

export type Designer = ReturnType<typeof useDesigner>;
