<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue';
import CardRender from './CardRender.vue';
import { sheetSides, type PrintSides, type SheetCard } from './cards';
import { ensureTenantFonts } from '@/lib/tenantFonts';
import type { CardLayout } from '@/types';

/**
 * Issued cards laid out for printing at their real size: one page per card side, each sized to its card
 * with a named CSS @page, so a card printer, "Save as PDF" and the server PDF engine all get exactly the
 * card. Used by the print page and the PDF render page, so both always match.
 */
const props = withDefaults(defineProps<{ cards: SheetCard[]; sides?: PrintSides; copies?: number }>(), { sides: 'both', copies: 1 });

// CSS pixels per millimetre: the page is laid out in mm
const PX_PER_MM = 96 / 25.4;

const root = ref<HTMLElement | null>(null);

const sizeKey = (layout: CardLayout) => `${layout.size_mm.width}x${layout.size_mm.height}`;
const boxOf = (layout: CardLayout) => Math.max(layout.size_mm.width, layout.size_mm.height) * PX_PER_MM;

/** Page sizes in the batch: each gets a named @page, so cards of different sizes print correctly together. */
const pageNames = computed(() => {
    const names = new Map<string, string>();
    for (const card of props.cards) {
        const key = sizeKey(card.layout);
        if (!names.has(key)) {
            names.set(key, `card-${names.size}`);
        }
    }

    return names;
});

const sheets = computed(() =>
    Array.from({ length: props.copies }, (_, copy) =>
        props.cards.flatMap((card) =>
            sheetSides(card.layout, props.sides).map((side) => ({ key: `${copy}-${card.id}-${side}`, card, side, page: pageNames.value.get(sizeKey(card.layout))! })),
        ),
    ).flat(),
);

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

// One named @page per card size; each sheet uses its page
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
    <main ref="root" class="print-sheets flex flex-col items-center gap-6 px-4 py-8" data-print-sheets>
        <section
            v-for="sheet in sheets"
            :key="sheet.key"
            class="print-sheet"
            :data-print-page="sheet.page"
            :data-card="sheet.card.id"
            :style="{ width: `${sheet.card.layout.size_mm.width}mm`, height: `${sheet.card.layout.size_mm.height}mm` }"
        >
            <CardRender :layout="sheet.card.layout" :values="sheet.card.values" :box="boxOf(sheet.card.layout)" :sides="[sheet.side]" />
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

@media print {
    html,
    body {
        margin: 0 !important;
        background: #fff !important;
    }

    .print-sheets {
        display: block !important;
        padding: 0 !important;
    }

    .print-sheet:not(:last-child) {
        break-after: page;
    }

    /* The card edge in the app (shadow, ring, rounded corners) is not part of the card */
    .print-sheet [data-side] {
        box-shadow: none !important;
        border-radius: 0 !important;
    }
}
</style>
