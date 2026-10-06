<script setup lang="ts">
import { onMounted, ref } from 'vue';
import CardSheets from './CardSheets.vue';
import type { PrintSides, SheetCard } from './cards';
import { http } from '@/lib/http';
import { provideTenantFonts, type FontFile } from '@/lib/tenantFonts';

/**
 * The cards alone, for the server PDF engine (Gotenberg): no sign-in, reached through a short-lived
 * signed link (CardRenderService::link) whose query is passed on unchanged to the data API. The engine
 * waits until `window.cardsReady` is set (CardRenderService::READY_EXPRESSION): true once the cards are
 * fully drawn; false on a failure, which is also thrown, so the engine stops at once instead of
 * waiting for its timeout or producing an empty PDF.
 */
declare global {
    interface Window {
        cardsReady?: boolean;
    }
}

const cards = ref<SheetCard[]>([]);
const sides = ref<PrintSides>('both');
const sheets = ref<InstanceType<typeof CardSheets> | null>(null);

async function render(): Promise<void> {
    // The signature covers the exact query, so it is forwarded as received
    const { data } = await http.get<{ data: { cards: (SheetCard & { printable: boolean })[]; fonts: FontFile[]; sides: PrintSides } }>(`/card-render${window.location.search}`);
    await provideTenantFonts(data.data.fonts);
    sides.value = data.data.sides;
    cards.value = data.data.cards.filter((card) => card.printable);
    if (!cards.value.length) {
        throw new Error('No printable cards to render.');
    }
    await sheets.value?.waitForAssets();
}

onMounted(async () => {
    try {
        await render();
        window.cardsReady = true;
    } catch (error) {
        window.cardsReady = false;
        // Thrown outside Vue (which would only log it), so the engine sees an uncaught exception and fails
        setTimeout(() => {
            throw error;
        });
    }
});
</script>

<template>
    <CardSheets ref="sheets" :cards="cards" :sides="sides" class="print:p-0" />
</template>
