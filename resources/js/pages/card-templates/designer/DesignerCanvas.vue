<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue';
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
    valueOf: (item: DesignerItem) => string;
    imageOf?: (item: DesignerItem) => string | null;
    editable: boolean;
    /** Keys of elements the server rejected. */
    invalidKeys?: number[];
    /** Grid step in mm that dragged positions and edges snap to; 0 = off. */
    snap?: number;
}>();

const emit = defineEmits<{
    select: [key: number | null];
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

/** Only one outline style applies, so the colours never compete. */
function outline(item: DesignerItem): string {
    if (props.invalidKeys?.includes(item.key)) {
        return 'outline-2 outline-solid outline-danger';
    }

    return item.key === props.selectedKey ? 'outline-2 outline-solid outline-brand' : 'outline-1 outline-dashed outline-slate-400/60';
}

let drag: { key: number; mode: 'move' | Handle; pointerX: number; pointerY: number; start: Rect; moved: boolean } | null = null;

function start(event: PointerEvent, item: DesignerItem, mode: 'move' | Handle): void {
    emit('select', item.key);
    if (!props.editable || event.button !== 0) {
        return;
    }
    event.preventDefault();
    drag = { key: item.key, mode, pointerX: event.clientX, pointerY: event.clientY, start: { x: item.x, y: item.y, width: item.width, height: item.height }, moved: false };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop);
    window.addEventListener('pointercancel', stop);
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
    if (drag.mode === 'move') {
        emit('rect', drag.key, { x: snapped(x + dx), y: snapped(y + dy), width, height });

        return;
    }

    let left = x;
    let top = y;
    let right = x + width;
    let bottom = y + height;
    if (drag.mode.includes('w')) {
        left = edge(snapped(x + dx), right, props.width, true);
    }
    if (drag.mode.includes('e')) {
        right = edge(snapped(right + dx), left, props.width, false);
    }
    if (drag.mode.includes('n')) {
        top = edge(snapped(y + dy), bottom, props.height, true);
    }
    if (drag.mode.includes('s')) {
        bottom = edge(snapped(bottom + dy), top, props.height, false);
    }
    emit('rect', drag.key, { x: roundMm(left), y: roundMm(top), width: roundMm(right - left), height: roundMm(bottom - top) });
}

function stop(): void {
    if (drag?.moved) {
        emit('changed', drag.key);
    }
    drag = null;
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', stop);
    window.removeEventListener('pointercancel', stop);
}

onBeforeUnmount(stop);
</script>

<template>
    <div
        class="relative shrink-0 touch-none rounded-[3%] shadow-lg ring-1 ring-black/10 select-none"
        :style="{ width: `${width * scale}px`, height: `${height * scale}px`, ...sideCss(background, background?.image_url) }"
        @pointerdown.self="emit('select', background?.key ?? null)"
    >
        <!-- What prints: clipped to the card's rounded corners -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-[inherit]">
            <div v-for="item in layers" :key="item.key" class="absolute" :style="box(item)" :data-content-key="item.key">
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
        <!-- Editing layer: outlines and handles stay visible above everything and past the corners -->
        <div
            v-for="item in layers"
            :key="item.key"
            class="absolute"
            :class="[editable ? 'cursor-move' : 'cursor-pointer', outline(item)]"
            :style="box(item)"
            :data-key="item.key"
            @pointerdown.stop="start($event, item, 'move')"
        >
            <template v-if="editable && item.key === selectedKey">
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
