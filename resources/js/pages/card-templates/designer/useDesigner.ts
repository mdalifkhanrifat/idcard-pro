import { computed, ref, watch } from 'vue';
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

/** Smallest element edge, in millimetres (thin enough for lines). */
export const MIN_SIZE = 0.2;

/** Undo steps kept in memory. */
const HISTORY_LIMIT = 100;

export type LayerMove = 'up' | 'down' | 'top' | 'bottom';

export type AlignMode = 'left' | 'center' | 'right' | 'top' | 'middle' | 'bottom';

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
    shape: { width: 30, height: 12, style: { background: '#1e3a8a' } },
    // A white fill keeps the quiet zone light on coloured cards.
    barcode: { width: 40, height: 10, field: 'employee_no', style: { font_family: 'Inter', font_size: 6, color: '#000000', background: '#ffffff', align: 'center' } },
};

/** Ready-made variants offered in the palette. */
export const PRESETS: Record<string, { type: DesignElementType; width: number; height: number; style: DesignElement['style'] }> = {
    line: { type: 'shape', width: 60, height: 0.3, style: { background: '#111111' } },
    band: { type: 'shape', width: 0, height: 10, style: { background: '#1e3a8a' } },
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
        library_image: item.library_image ?? null,
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

    /*
     * Multiple selection. `selectedKey` stays the primary element (shown in the settings panel);
     * `selectedKeys` holds every selected element and follows it when it is set directly.
     */
    const selectedKeys = ref<number[]>([]);
    watch(selectedKey, (key) => {
        if (key === null) {
            selectedKeys.value = [];
        } else if (!selectedKeys.value.includes(key)) {
            selectedKeys.value = [key];
        }
    });
    const selection = computed(() => items.value.filter((item) => selectedKeys.value.includes(item.key)));

    /** Select one element, or add/remove it from the selection (Shift/Ctrl-click). */
    function select(key: number | null, additive = false): void {
        if (key === null || !additive) {
            selectedKeys.value = key === null ? [] : [key];
            selectedKey.value = key;

            return;
        }
        const keys = selectedKeys.value.includes(key) ? selectedKeys.value.filter((candidate) => candidate !== key) : [...selectedKeys.value, key];
        selectedKeys.value = keys;
        selectedKey.value = keys.at(-1) ?? null;
    }

    function selectMany(keys: number[]): void {
        selectedKeys.value = keys;
        selectedKey.value = keys.at(-1) ?? null;
    }
    const snapshot = computed(() => JSON.stringify(items.value.map(toPayload)));
    const dirty = computed(() => snapshot.value !== savedSnapshot.value);

    /*
     * Undo history: whole-layout states (with local keys and image links). Changes are recorded once they
     * settle, so a drag or a burst of typing is one step.
     */
    const history = ref<string[]>([]);
    const historyIndex = ref(-1);
    const canUndo = computed(() => historyIndex.value > 0);
    const canRedo = computed(() => historyIndex.value < history.value.length - 1);
    let historyTimer: ReturnType<typeof setTimeout> | undefined;

    function record(): void {
        clearTimeout(historyTimer);
        const state = JSON.stringify(items.value);
        if (state === history.value[historyIndex.value]) {
            return;
        }
        history.value = [...history.value.slice(0, historyIndex.value + 1), state].slice(-HISTORY_LIMIT);
        historyIndex.value = history.value.length - 1;
    }

    function resetHistory(): void {
        clearTimeout(historyTimer);
        history.value = [JSON.stringify(items.value)];
        historyIndex.value = 0;
    }

    watch(
        items,
        () => {
            clearTimeout(historyTimer);
            historyTimer = setTimeout(record, 400);
        },
        { deep: true },
    );

    function restore(index: number): void {
        historyIndex.value = index;
        items.value = JSON.parse(history.value[index]) as DesignerItem[];
        clearTimeout(historyTimer);
        if (!items.value.some((item) => item.key === selectedKey.value)) {
            selectedKey.value = null;
        }
    }

    function undo(): void {
        record();
        if (canUndo.value) {
            restore(historyIndex.value - 1);
        }
    }

    function redo(): void {
        record();
        if (canRedo.value) {
            restore(historyIndex.value + 1);
        }
    }

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
            resetHistory();
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
    function add(type: DesignElementType, text = 'Text', preset?: string): void {
        if (type === 'background') {
            const existing = sideItems.value.find((item) => item.type === 'background');
            if (existing) {
                selectedKey.value = existing.key;

                return;
            }
        }

        const variant = preset ? PRESETS[preset] : undefined;
        // A band spans the full card width.
        const defaults = variant ? { ...variant, width: variant.width || size.value.width } : DEFAULTS[type];
        const rect =
            type === 'background'
                ? { x: 0, y: 0, width: size.value.width, height: size.value.height }
                : preset === 'band'
                  ? { x: 0, y: 0, width: size.value.width, height: defaults.height }
                : clampRect({ x: (size.value.width - defaults.width) / 2, y: (size.value.height - defaults.height) / 2, width: defaults.width, height: defaults.height });
        const item = toItem({
            side: side.value,
            type,
            field: 'field' in defaults ? (defaults.field ?? null) : null,
            label: null,
            content: type === 'text' ? text : null,
            qr_lines: 'qr_lines' in defaults && defaults.qr_lines ? defaults.qr_lines.map((line) => ({ ...line })) : null,
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

    function remove(key: number): void {
        if (items.value.find((item) => item.key === key)?.style.locked) {
            return;
        }
        items.value = items.value.filter((item) => item.key !== key);
        if (selectedKey.value === key) {
            selectedKey.value = null;
        }
    }

    /** Selected elements that may be moved (not locked, not the background). */
    function movable(keys: number[]): DesignerItem[] {
        return items.value.filter((item) => keys.includes(item.key) && item.type !== 'background' && !item.style.locked);
    }

    /**
     * Move elements together by a number of millimetres. The step is shortened so the whole group
     * stays on the card and keeps its arrangement.
     */
    function moveBy(keys: number[], dx: number, dy: number, from?: Map<number, Rect>): void {
        const group = movable(keys);
        if (!group.length) {
            return;
        }
        const start = (item: DesignerItem): Rect => from?.get(item.key) ?? item;
        const left = Math.min(...group.map((item) => start(item).x));
        const top = Math.min(...group.map((item) => start(item).y));
        const right = Math.max(...group.map((item) => start(item).x + start(item).width));
        const bottom = Math.max(...group.map((item) => start(item).y + start(item).height));
        const stepX = Math.min(Math.max(dx, -left), size.value.width - right);
        const stepY = Math.min(Math.max(dy, -top), size.value.height - bottom);
        for (const item of group) {
            const rect = start(item);
            Object.assign(item, { x: roundMm(rect.x + stepX), y: roundMm(rect.y + stepY) });
        }
    }

    function nudge(key: number, dx: number, dy: number): void {
        moveBy([key], dx, dy);
    }

    /**
     * Line elements up: with several selected, along the selection's edges or centre; with one, on the card.
     */
    function align(keys: number[], mode: AlignMode): void {
        const group = movable(keys);
        if (!group.length) {
            return;
        }
        const bounds =
            group.length > 1
                ? {
                      left: Math.min(...group.map((item) => item.x)),
                      top: Math.min(...group.map((item) => item.y)),
                      right: Math.max(...group.map((item) => item.x + item.width)),
                      bottom: Math.max(...group.map((item) => item.y + item.height)),
                  }
                : { left: 0, top: 0, right: size.value.width, bottom: size.value.height };
        for (const item of group) {
            const x = { left: bounds.left, center: (bounds.left + bounds.right - item.width) / 2, right: bounds.right - item.width }[mode as 'left' | 'center' | 'right'];
            const y = { top: bounds.top, middle: (bounds.top + bounds.bottom - item.height) / 2, bottom: bounds.bottom - item.height }[mode as 'top' | 'middle' | 'bottom'];
            Object.assign(item, clampRect({ x: x ?? item.x, y: y ?? item.y, width: item.width, height: item.height }));
        }
    }

    /** Equal gaps between three or more elements, keeping the outer two in place. */
    function distribute(keys: number[], axis: 'horizontal' | 'vertical'): void {
        const group = movable(keys);
        if (group.length < 3) {
            return;
        }
        const position = axis === 'horizontal' ? 'x' : 'y';
        const length = axis === 'horizontal' ? 'width' : 'height';
        const sorted = [...group].sort((a, b) => a[position] - b[position]);
        const first = sorted[0];
        const last = sorted[sorted.length - 1];
        const used = sorted.reduce((total, item) => total + item[length], 0);
        const gap = (last[position] + last[length] - first[position] - used) / (sorted.length - 1);
        let cursor = first[position];
        for (const item of sorted) {
            item[position] = roundMm(cursor);
            cursor += item[length] + gap;
        }
    }

    /** Copy an element just above itself, slightly offset (a side keeps a single background). */
    function duplicate(key: number): void {
        const index = items.value.findIndex((item) => item.key === key);
        const source = items.value[index];
        if (!source || source.type === 'background') {
            return;
        }
        const copy = toItem({ ...source, id: undefined });
        Object.assign(copy, clampRect({ x: source.x + 2, y: source.y + 2, width: source.width, height: source.height }));
        items.value.splice(index + 1, 0, copy);
        selectedKey.value = copy.key;
    }

    /** Replace the whole layout (imported file or ready-made design); stays unsaved and can be undone. */
    function replaceAll(elements: DesignElement[]): void {
        items.value = elements.map((element) => toItem(element));
        selectedKey.value = null;
    }

    /** Lock (no moving or editing) or hide (kept, not printed) an element. */
    function toggleFlag(key: number, flag: 'locked' | 'hidden'): void {
        const item = items.value.find((candidate) => candidate.key === key);
        if (!item) {
            return;
        }
        if (item.style[flag]) {
            delete item.style[flag];
        } else {
            item.style[flag] = true;
        }
    }

    /**
     * Copy an element to the same place on the other side, on top. Returns false when that side already
     * has a background (one per side).
     */
    function copyToOtherSide(key: number): boolean {
        const source = items.value.find((item) => item.key === key);
        if (!source) {
            return false;
        }
        const target: CardSide = source.side === 'front' ? 'back' : 'front';
        if (source.type === 'background' && items.value.some((item) => item.side === target && item.type === 'background')) {
            return false;
        }
        const copy = toItem({ ...source, id: undefined, side: target });
        if (source.type === 'background') {
            items.value.unshift(copy);
        } else {
            items.value.push(copy);
        }

        return true;
    }

    /**
     * Change the stacking order within the element's side. The background stays at the bottom,
     * because it is drawn behind everything else.
     */
    function moveLayer(key: number, move: LayerMove): void {
        const item = items.value.find((candidate) => candidate.key === key);
        if (!item || item.type === 'background') {
            return;
        }
        const others = items.value.filter((candidate) => candidate.side !== item.side);
        const sideLayers = items.value.filter((candidate) => candidate.side === item.side);
        const backgrounds = sideLayers.filter((candidate) => candidate.type === 'background');
        const stack = sideLayers.filter((candidate) => candidate.type !== 'background' && candidate.key !== key);
        const position = sideLayers.filter((candidate) => candidate.type !== 'background').indexOf(item);
        const target = { up: position + 1, down: position - 1, top: stack.length, bottom: 0 }[move];
        stack.splice(Math.min(Math.max(target, 0), stack.length), 0, item);
        items.value = [...others, ...backgrounds, ...stack];
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

    return {
        template,
        catalog,
        items,
        side,
        selectedKey,
        selectedKeys,
        selection,
        select,
        selectMany,
        moveBy,
        align,
        distribute,
        loading,
        saving,
        errors,
        dirty,
        size,
        sideItems,
        selected,
        canUndo,
        canRedo,
        load,
        showSide,
        add,
        remove,
        nudge,
        duplicate,
        moveLayer,
        toggleFlag,
        copyToOtherSide,
        replaceAll,
        setRect,
        clampRect,
        save,
        undo,
        redo,
    };
}

export type Designer = ReturnType<typeof useDesigner>;
