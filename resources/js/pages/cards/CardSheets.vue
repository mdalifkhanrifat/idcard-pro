<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue';
import CardRender from './CardRender.vue';
import { printPages, type PlacedCard, type PrintPage, type PrintSheetSize, type PrintSides, type SheetCard } from './cards';
import { ensureTenantFonts } from '@/lib/tenantFonts';
import type { CardLayout } from '@/types';

/**
 * Issued cards laid out for printing at their real size (printPages): one page per card side, each sized
 * to its card (card printers), or several cards on a paper sheet with crop marks. Every page size gets a
 * named CSS @page, so the browser's print, "Save as PDF" and the server PDF engine all get exactly these
 * pages. Used by the print page and the PDF render page, so both always match.
 */
const props = withDefaults(defineProps<{ cards: SheetCard[]; sides?: PrintSides; copies?: number; sheet?: PrintSheetSize | null }>(), {
    sides: 'both',
    copies: 1,
    sheet: null,
});

// CSS pixels per millimetre: the page is laid out in mm
const PX_PER_MM = 96 / 25.4;
/** Crop marks: gap from the card corner and length (mm), drawn along the card edges into the gaps. */
const MARK_OFFSET_MM = 1;
const MARK_LENGTH_MM = 3;

const root = ref<HTMLElement | null>(null);

const boxOf = (layout: CardLayout) => Math.max(layout.size_mm.width, layout.size_mm.height) * PX_PER_MM;

const pages = computed(() => printPages(props.cards, props.sides, props.copies, props.sheet));

/** Page sizes in the print: each gets a named @page, so pages of different sizes print correctly together. */
const sizeKey = (page: PrintPage) => `${page.width}x${page.height}`;
const pageNames = computed(() => {
    const names = new Map<string, string>();
    for (const page of pages.value) {
        if (!names.has(sizeKey(page))) {
            names.set(sizeKey(page), `card-${names.size}`);
        }
    }

    return names;
});

/** Short lines along each card edge, just outside its corners, to cut along. */
function marks(item: PlacedCard): string {
    const { width, height } = item.card.layout.size_mm;
    const from = MARK_OFFSET_MM;
    const to = MARK_OFFSET_MM + MARK_LENGTH_MM;

    return [
        [item.x, item.y, -1, -1],
        [item.x + width, item.y, 1, -1],
        [item.x, item.y + height, -1, 1],
        [item.x + width, item.y + height, 1, 1],
    ]
        .map(([x, y, dx, dy]) => `M${x + dx * from} ${y}H${x + dx * to}M${x} ${y + dy * from}V${y + dy * to}`)
        .join('');
}

/** Fonts, photos and background images must be loaded, or the card is printed without them. */
async function waitForAssets(): Promise<void> {
    await ensureTenantFonts();
    await nextTick();
    if (root.value) {
        const urls = new Set<string>();
        root.value.querySelectorAll('img').forEach((img) => img.currentSrc && urls.add(img.currentSrc));
        root.value.querySelectorAll<HTMLElement>('[data-side]').forEach((side) => {
            for (const match of getComputedStyle(side).backgroundImage.matchAll(/url\("?(.+?)"?\)/g)) {
                urls.add(match[1]);
            }
        });
        await Promise.all([...urls].map((url) => new Promise((done) => Object.assign(new Image(), { onload: done, onerror: done, src: url }))));
    }
    await document.fonts.ready;
    // Auto-fit text measures itself again once fonts are in; give it two frames
    await new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
}

defineExpose({ waitForAssets });

// One named @page per page size; each page uses its own
const pageStyle = document.createElement('style');
watchEffect(() => {
    pageStyle.textContent = [...pageNames.value]
        .map(([key, name]) => {
            const [width, height] = key.split('x');

            return `@page ${name} { size: ${width}mm ${height}mm; margin: 0; } [data-print-page="${name}"] { page: ${name}; }`;
        })
        .join('\n');
});

onMounted(() => document.head.append(pageStyle));
onBeforeUnmount(() => pageStyle.remove());
</script>

<template>
    <main ref="root" class="print-sheets flex flex-col gap-6 overflow-x-auto px-4 py-8" data-print-sheets>
        <section
            v-for="page in pages"
            :key="page.key"
            class="print-sheet relative mx-auto"
            :class="sheet && 'print-paper'"
            :data-print-page="pageNames.get(sizeKey(page))"
            :data-print-paper="sheet?.key"
            :style="{ width: `${page.width}mm`, height: `${page.height}mm` }"
        >
            <div
                v-for="item in page.items"
                :key="item.key"
                class="absolute"
                :data-card="item.card.id"
                :style="{ left: `${item.x}mm`, top: `${item.y}mm`, width: `${item.card.layout.size_mm.width}mm`, height: `${item.card.layout.size_mm.height}mm` }"
            >
                <CardRender :layout="item.card.layout" :values="item.card.values" :box="boxOf(item.card.layout)" :sides="[item.side]" />
            </div>
            <svg v-if="page.marks" class="pointer-events-none absolute inset-0 size-full" :viewBox="`0 0 ${page.width} ${page.height}`" aria-hidden="true" data-crop-marks>
                <path v-for="item in page.items" :key="item.key" :d="marks(item)" fill="none" stroke="#000" stroke-width="0.15" />
            </svg>
        </section>
    </main>
</template>

<style>
.print-sheet {
    flex: none;
    overflow: hidden;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* A paper sheet on screen: white paper whatever the theme, as it prints */
.print-paper {
    background: #fff;
    box-shadow: 0 1px 4px rgb(0 0 0 / 0.25);
}

@media print {
    html,
    body {
        margin: 0 !important;
        background: #fff !important;
    }

    .print-sheets {
        display: block !important;
        padding: 0 !important;
        overflow: visible !important;
    }

    .print-sheet:not(:last-child) {
        break-after: page;
    }

    .print-paper {
        margin: 0 !important;
        box-shadow: none;
    }

    /* The card edge in the app (shadow, ring, rounded corners) is not part of the card */
    .print-sheet [data-side] {
        box-shadow: none !important;
        border-radius: 0 !important;
    }
}
</style>
