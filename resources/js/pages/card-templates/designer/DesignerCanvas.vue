<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import './cardFonts';
import DesignerElement from './DesignerElement.vue';
import { sideCss } from './styles';
import { MIN_SIZE, roundMm, type DesignerItem, type Rect } from './useDesigner';

/**
 * One card side drawn at `scale` pixels per millimetre. The background element fills the side
 * (legacy bg_image/back_bg_image); every other element is placed by its mm position and, when
 * editable, can be dragged (legacy jQuery UI draggable) and resized with its handles (new).
 */
const props = defineProps<{
    width: number;
    height: number;
    scale: number;
    items: DesignerItem[];
    selectedKey: number | null;
    /** Every selected element (multiple selection). */
    selectedKeys?: number[];
    valueOf: (item: DesignerItem) => string;
    imageOf?: (item: DesignerItem) => string | null;
    editable: boolean;
    /** Keys of elements the server rejected. */
    invalidKeys?: number[];
    /** Grid step in mm that dragged positions and edges snap to; 0 = off. */
    snap?: number;
    /** Only what prints: no outlines, handles or hidden elements (thumbnails). */
    bare?: boolean;
    /** Show the safe area and centre lines. */
    guides?: boolean;
}>();

/** Keep important content this far inside the card edge (cutting and printer tolerance). */
const SAFE_MARGIN_MM = 3;

const emit = defineEmits<{
    /** `additive`: Shift/Ctrl-click adds to or removes from the selection. */
    select: [key: number | null, additive?: boolean];
    /** Elements inside a dragged selection box. */
    selectMany: [keys: number[]];
    /** Several selected elements dragged together, by mm from where they started. */
    moveGroup: [keys: number[], dx: number, dy: number, from: Map<number, Rect>];
    rect: [key: number, rect: Rect];
    /** A drag or resize finished and changed something. */
    changed: [key: number];
}>();

type Handle = 'n' | 's' | 'e' | 'w' | 'ne' | 'nw' | 'se' | 'sw';
const HANDLES: { handle: Handle; style: Record<string, string>; cursor: string }[] = [
    { handle: 'nw', style: { left: '0', top: '0' }, cursor: 'nwse-resize' },
    { handle: 'n', style: { left: '50%', top: '0' }, cursor: 'ns-resize' },
    { handle: 'ne', style: { left: '100%', top: '0' }, cursor: 'nesw-resize' },
    { handle: 'e', style: { left: '100%', top: '50%' }, cursor: 'ew-resize' },
    { handle: 'se', style: { left: '100%', top: '100%' }, cursor: 'nwse-resize' },
    { handle: 's', style: { left: '50%', top: '100%' }, cursor: 'ns-resize' },
    { handle: 'sw', style: { left: '0', top: '100%' }, cursor: 'nesw-resize' },
    { handle: 'w', style: { left: '0', top: '50%' }, cursor: 'ew-resize' },
];

const background = computed(() => props.items.find((item) => item.type === 'background' && !(props.bare && item.style.hidden)) ?? null);
const layers = computed(() => props.items.filter((item) => item.type !== 'background' && !(props.bare && item.style.hidden)));

function box(item: DesignerItem): Record<string, string> {
    return {
        left: `${item.x * props.scale}px`,
        top: `${item.y * props.scale}px`,
        width: `${item.width * props.scale}px`,
        height: `${item.height * props.scale}px`,
    };
}

const selectedSet = computed(() => new Set(props.selectedKeys ?? (props.selectedKey === null ? [] : [props.selectedKey])));
/** Resize handles only make sense for a single element. */
const showHandles = computed(() => selectedSet.value.size <= 1);

/** Only one outline style applies, so the colours never compete. */
function outline(item: DesignerItem): string {
    if (props.invalidKeys?.includes(item.key)) {
        return 'outline-2 outline-solid outline-danger';
    }

    return selectedSet.value.has(item.key) ? 'outline-2 outline-solid outline-brand' : 'outline-1 outline-dashed outline-slate-400/60';
}

let drag: {
    key: number;
    mode: 'move' | Handle;
    pointerX: number;
    pointerY: number;
    start: Rect;
    moved: boolean;
    /** Start positions of the other selected elements when moving a group. */
    group: Map<number, Rect> | null;
} | null = null;

function start(event: PointerEvent, item: DesignerItem, mode: 'move' | Handle): void {
    if (event.shiftKey || event.ctrlKey || event.metaKey) {
        emit('select', item.key, true);

        return;
    }
    const inGroup = mode === 'move' && selectedSet.value.size > 1 && selectedSet.value.has(item.key);
    if (!inGroup) {
        emit('select', item.key);
    }
    if (!props.editable || event.button !== 0 || item.style.locked) {
        if (inGroup) {
            emit('select', item.key);
        }

        return;
    }
    event.preventDefault();
    const group = inGroup ? new Map(props.items.filter((candidate) => selectedSet.value.has(candidate.key)).map((candidate) => [candidate.key, { x: candidate.x, y: candidate.y, width: candidate.width, height: candidate.height }])) : null;
    drag = { key: item.key, mode, pointerX: event.clientX, pointerY: event.clientY, start: { x: item.x, y: item.y, width: item.width, height: item.height }, moved: false, group };
    collectTargets(new Set(group ? group.keys() : [item.key]));
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop);
    window.addEventListener('pointercancel', stop);
}

/*
 * Smart guides: while dragging, edges and centres jump to the edges and centres of other elements
 * and of the card when they come within a few screen pixels. Hold Alt to place freely.
 */
const GUIDE_PX = 6;
const guideLines = ref<{ x: number[]; y: number[] }>({ x: [], y: [] });
let guideTargets: { x: number[]; y: number[] } = { x: [], y: [] };

function collectTargets(excluded: Set<number>): void {
    const others = layers.value.filter((item) => !excluded.has(item.key) && !item.style.hidden);
    guideTargets = {
        x: [0, props.width / 2, props.width, ...others.flatMap((item) => [item.x, item.x + item.width / 2, item.x + item.width])],
        y: [0, props.height / 2, props.height, ...others.flatMap((item) => [item.y, item.y + item.height / 2, item.y + item.height])],
    };
}

/** Closest guide for any of the given positions: the shift to apply and where to draw the line. */
function nearestGuide(positions: number[], targets: number[]): { shift: number; line: number } | null {
    let best: { shift: number; line: number } | null = null;
    for (const position of positions) {
        for (const target of targets) {
            const shift = target - position;
            if (Math.abs(shift) * props.scale <= GUIDE_PX && (!best || Math.abs(shift) < Math.abs(best.shift))) {
                best = { shift, line: target };
            }
        }
    }

    return best;
}

/** Round a position to the snap grid (when snapping is on). */
function snapped(value: number): number {
    return props.snap ? Math.round(value / props.snap) * props.snap : value;
}

/** Moves one edge, keeping it on the card and at least MIN_SIZE away from the opposite edge. */
function edge(value: number, fixed: number, limit: number, before: boolean): number {
    return before ? Math.min(Math.max(value, 0), fixed - MIN_SIZE) : Math.max(Math.min(value, limit), fixed + MIN_SIZE);
}

function move(event: PointerEvent): void {
    if (!drag) {
        return;
    }
    const dx = (event.clientX - drag.pointerX) / props.scale;
    const dy = (event.clientY - drag.pointerY) / props.scale;
    if (!drag.moved && Math.abs(dx) * props.scale < 2 && Math.abs(dy) * props.scale < 2) {
        return;
    }
    drag.moved = true;

    const { x, y, width, height } = drag.start;
    const useGuides = !event.altKey;
    guideLines.value = { x: [], y: [] };
    if (drag.mode === 'move') {
        let left = snapped(x + dx);
        let top = snapped(y + dy);
        if (useGuides) {
            // Guides follow the grabbed element; for a group the others keep their distance to it.
            const guideX = nearestGuide([x + dx, x + dx + width / 2, x + dx + width], guideTargets.x);
            const guideY = nearestGuide([y + dy, y + dy + height / 2, y + dy + height], guideTargets.y);
            if (guideX) {
                left = x + dx + guideX.shift;
                guideLines.value.x = [guideX.line];
            }
            if (guideY) {
                top = y + dy + guideY.shift;
                guideLines.value.y = [guideY.line];
            }
        }
        if (drag.group) {
            emit('moveGroup', [...drag.group.keys()], left - x, top - y, drag.group);
        } else {
            emit('rect', drag.key, { x: left, y: top, width, height });
        }

        return;
    }

    let left = x;
    let top = y;
    let right = x + width;
    let bottom = y + height;
    /** A dragged edge jumps to a nearby guide, otherwise to the grid. */
    const guided = (value: number, axis: 'x' | 'y'): number => {
        const guide = useGuides ? nearestGuide([value], guideTargets[axis]) : null;
        if (guide) {
            guideLines.value[axis] = [guide.line];

            return guide.line;
        }

        return snapped(value);
    };
    if (drag.mode.includes('w')) {
        left = edge(guided(x + dx, 'x'), right, props.width, true);
    }
    if (drag.mode.includes('e')) {
        right = edge(guided(right + dx, 'x'), left, props.width, false);
    }
    if (drag.mode.includes('n')) {
        top = edge(guided(y + dy, 'y'), bottom, props.height, true);
    }
    if (drag.mode.includes('s')) {
        bottom = edge(guided(bottom + dy, 'y'), top, props.height, false);
    }
    emit('rect', drag.key, { x: roundMm(left), y: roundMm(top), width: roundMm(right - left), height: roundMm(bottom - top) });
}

function stop(): void {
    if (drag?.moved) {
        emit('changed', drag.key);
    } else if (drag?.group) {
        // A click (no drag) inside a multiple selection narrows it to that element.
        emit('select', drag.key);
    }
    drag = null;
    guideLines.value = { x: [], y: [] };
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', stop);
    window.removeEventListener('pointercancel', stop);
}

/*
 * Selection box: dragging on an empty part of the card selects every element it touches.
 */
const marquee = ref<{ x: number; y: number; width: number; height: number } | null>(null);
let marqueeStart: { x: number; y: number; left: number; top: number } | null = null;

function startMarquee(event: PointerEvent): void {
    if (!props.editable || event.button !== 0) {
        emit('select', background.value?.key ?? null);

        return;
    }
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    marqueeStart = { x: (event.clientX - rect.left) / props.scale, y: (event.clientY - rect.top) / props.scale, left: rect.left, top: rect.top };
    marquee.value = null;
    window.addEventListener('pointermove', moveMarquee);
    window.addEventListener('pointerup', endMarquee);
}

function moveMarquee(event: PointerEvent): void {
    if (!marqueeStart) {
        return;
    }
    const x = (event.clientX - marqueeStart.left) / props.scale;
    const y = (event.clientY - marqueeStart.top) / props.scale;
    marquee.value = { x: Math.min(x, marqueeStart.x), y: Math.min(y, marqueeStart.y), width: Math.abs(x - marqueeStart.x), height: Math.abs(y - marqueeStart.y) };
}

function endMarquee(): void {
    const box = marquee.value;
    window.removeEventListener('pointermove', moveMarquee);
    window.removeEventListener('pointerup', endMarquee);
    marqueeStart = null;
    marquee.value = null;
    if (!box || (box.width * props.scale < 3 && box.height * props.scale < 3)) {
        emit('select', background.value?.key ?? null);

        return;
    }
    emit(
        'selectMany',
        layers.value
            .filter((item) => item.x < box.x + box.width && item.x + item.width > box.x && item.y < box.y + box.height && item.y + item.height > box.y)
            .map((item) => item.key),
    );
}

onBeforeUnmount(() => {
    stop();
    window.removeEventListener('pointermove', moveMarquee);
    window.removeEventListener('pointerup', endMarquee);
});
</script>

<template>
    <div
        class="relative shrink-0 touch-none rounded-[3%] shadow-lg ring-1 ring-black/10 select-none"
        :style="{ width: `${width * scale}px`, height: `${height * scale}px`, ...sideCss(background, background?.image_url) }"
        @pointerdown.self="startMarquee"
    >
        <!-- What prints: clipped to the card's rounded corners -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-[inherit]">
            <div v-for="item in layers" :key="item.key" class="absolute" :class="item.style.hidden && 'opacity-25'" :style="box(item)" :data-content-key="item.key">
                <DesignerElement :element="item" :scale="scale" :value="valueOf(item)" :image-url="imageOf?.(item)" />
            </div>
        </div>
        <div
            v-if="snap"
            class="pointer-events-none absolute inset-0 rounded-[inherit] opacity-60"
            :style="{
                backgroundImage: 'linear-gradient(to right, rgb(148 163 184 / 0.35) 1px, transparent 1px), linear-gradient(to bottom, rgb(148 163 184 / 0.35) 1px, transparent 1px)',
                backgroundSize: `${snap * scale}px ${snap * scale}px`,
            }"
            data-grid
        />
        <div
            v-if="marquee"
            class="pointer-events-none absolute z-20 border border-[var(--brand)] bg-[var(--brand)]/10"
            :style="{ left: `${marquee.x * scale}px`, top: `${marquee.y * scale}px`, width: `${marquee.width * scale}px`, height: `${marquee.height * scale}px` }"
            data-marquee
        />
        <div v-for="line in guideLines.x" :key="`gx${line}`" class="pointer-events-none absolute inset-y-0 z-20 w-px bg-fuchsia-500" :style="{ left: `${line * scale}px` }" data-guide-line />
        <div v-for="line in guideLines.y" :key="`gy${line}`" class="pointer-events-none absolute inset-x-0 z-20 h-px bg-fuchsia-500" :style="{ top: `${line * scale}px` }" data-guide-line />
        <template v-if="guides">
            <div
                class="pointer-events-none absolute rounded-[2%] border border-dashed border-rose-500/70"
                :style="{ inset: `${SAFE_MARGIN_MM * scale}px` }"
                data-safe-area
            />
            <div class="pointer-events-none absolute inset-y-0 left-1/2 border-l border-dashed border-sky-500/60" />
            <div class="pointer-events-none absolute inset-x-0 top-1/2 border-t border-dashed border-sky-500/60" />
        </template>
        <!-- Editing layer: outlines and handles stay visible above everything and past the corners -->
        <div
            v-for="item in bare ? [] : layers"
            :key="item.key"
            class="absolute"
            :class="[editable ? 'cursor-move' : 'cursor-pointer', outline(item)]"
            :style="box(item)"
            :data-key="item.key"
            @pointerdown.stop="start($event, item, 'move')"
        >
            <template v-if="editable && showHandles && item.key === selectedKey && !item.style.locked">
                <span
                    v-for="handle in HANDLES"
                    :key="handle.handle"
                    class="absolute z-10 size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-sm border border-white bg-[var(--brand)] shadow"
                    :style="{ ...handle.style, cursor: handle.cursor }"
                    :data-handle="handle.handle"
                    @pointerdown.stop="start($event, item, handle.handle)"
                />
            </template>
        </div>
    </div>
</template>
