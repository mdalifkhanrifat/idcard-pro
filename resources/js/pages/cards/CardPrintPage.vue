<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, CheckCircle2, Loader2, Printer, X } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import CardRender from './CardRender.vue';
import { displayStatus, missingImages, useCardReasons, type CardImageKey } from './cards';
import { http, toApiError } from '@/lib/http';
import { ensureTenantFonts } from '@/lib/tenantFonts';
import { useUiStore } from '@/stores/ui';
import type { CardLayout, CardSide, CardValues, IssuedCard } from '@/types';

/**
 * Prints issued cards at their real size: one page per card side, sized to the card (CSS @page), so a
 * card printer or a PDF printer gets exactly the card. Opened with ?ids=a,b for one card or a batch.
 * Browsers do not say whether printing worked, so the user confirms before the print is logged.
 */
interface Entry {
    id: string;
    card: IssuedCard | null;
    layout: CardLayout | null;
    values: CardValues | null;
    /** Why the card is left out: not found, or not printable (revoked, replaced, expired). */
    problem: string | null;
    missing: CardImageKey[];
}

type SideChoice = 'both' | 'front' | 'back';

// CSS pixels per millimetre: the page is laid out in mm, so the card prints at its real size
const PX_PER_MM = 96 / 25.4;
const PARALLEL = 6;

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const ui = useUiStore();
const { printOptions, ready: optionsReady } = useCardReasons();

const entries = ref<Entry[]>([]);
const loading = ref(true);
const assetsReady = ref(false);
const copies = ref(1);
const sides = ref<SideChoice>('both');
const confirming = ref(false);
const note = ref('');
const noteError = ref<string[]>([]);
const logging = ref(false);
const logged = ref(0);
const sheetsEl = ref<HTMLElement | null>(null);

const ids = computed(() => [...new Set(String(route.query.ids ?? '').split(',').map((id) => id.trim()).filter(Boolean))]);
const tooMany = computed(() => ids.value.length > printOptions.value.max_batch);
const printable = computed(() => entries.value.filter((entry) => !entry.problem));
const skipped = computed(() => entries.value.filter((entry) => entry.problem));
const withMissing = computed(() => printable.value.filter((entry) => entry.missing.length));
const anyDoubleSided = computed(() => printable.value.some((entry) => entry.layout?.is_double_sided));

/** Page sizes in the batch: each gets a named @page, so cards of different sizes print correctly together. */
const pageNames = computed(() => {
    const names = new Map<string, string>();
    for (const entry of printable.value) {
        const key = sizeKey(entry.layout!);
        if (!names.has(key)) {
            names.set(key, `card-${names.size}`);
        }
    }

    return names;
});

const sheets = computed(() =>
    Array.from({ length: copies.value }, (_, copy) =>
        printable.value.flatMap((entry) =>
            sidesOf(entry.layout!).map((side) => ({ key: `${copy}-${entry.id}-${side}`, entry, side, page: pageNames.value.get(sizeKey(entry.layout!))! })),
        ),
    ).flat(),
);

function sizeKey(layout: CardLayout): string {
    return `${layout.size_mm.width}x${layout.size_mm.height}`;
}

function sidesOf(layout: CardLayout): CardSide[] {
    if (!layout.is_double_sided) {
        // A single-sided card has no back to print in a "back only" pass
        return sides.value === 'back' ? [] : ['front'];
    }

    return sides.value === 'both' ? ['front', 'back'] : [sides.value];
}

const boxOf = (layout: CardLayout) => Math.max(layout.size_mm.width, layout.size_mm.height) * PX_PER_MM;

async function loadEntry(id: string): Promise<Entry> {
    try {
        const { data } = await http.get(`/cards/${id}`);
        const card: IssuedCard = data.data;
        const problem = card.printable ? null : t('cards.print.notPrintable', { status: t(`cards.statuses.${displayStatus(card)}`) });

        return { id, card, layout: data.meta.layout, values: data.meta.values, problem, missing: missingImages(data.meta.layout, data.meta.values) };
    } catch (error) {
        const apiError = toApiError(error);
        const problem = apiError.status === 404 ? t('cards.print.notFound') : apiError.message;

        return { id, card: null, layout: null, values: null, problem, missing: [] };
    }
}

async function load(): Promise<void> {
    await optionsReady;
    if (tooMany.value || !ids.value.length) {
        loading.value = false;

        return;
    }
    const loaded: Entry[] = [];
    for (let start = 0; start < ids.value.length; start += PARALLEL) {
        loaded.push(...(await Promise.all(ids.value.slice(start, start + PARALLEL).map(loadEntry))));
    }
    entries.value = loaded;
    loading.value = false;
    await waitForAssets();
    // Print straight away (legacy behaviour) unless there is something to read first
    if (printable.value.length && !skipped.value.length && !withMissing.value.length) {
        print();
    }
}

/** Fonts, photos and background images must be loaded, or the browser prints the card without them. */
async function waitForAssets(): Promise<void> {
    assetsReady.value = false;
    await ensureTenantFonts();
    await nextTick();
    const root = sheetsEl.value;
    if (root) {
        const urls = new Set<string>();
        root.querySelectorAll('img').forEach((img) => img.currentSrc && urls.add(img.currentSrc));
        root.querySelectorAll<HTMLElement>('[data-side]').forEach((side) => {
            for (const match of getComputedStyle(side).backgroundImage.matchAll(/url\("?(.+?)"?\)/g)) {
                urls.add(match[1]);
            }
        });
        await Promise.all([...urls].map((url) => new Promise((done) => Object.assign(new Image(), { onload: done, onerror: done, src: url }))));
    }
    await document.fonts.ready;
    // Auto-fit text measures itself again once fonts are in; give it two frames
    await new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
    assetsReady.value = true;
}

function print(): void {
    confirming.value = false;
    awaitingBacks.value = backPending.value;
    window.print();
    // Most browsers block until the dialog closes; afterprint covers the others
    confirming.value = true;
}

function onAfterPrint(): void {
    confirming.value = true;
}

/** Front-only pass of double-sided cards (printers without duplex): print the backs, then log once. */
const backPending = computed(() => sides.value === 'front' && anyDoubleSided.value);
// What the last pass printed, fixed when printing so the closing dialog does not change its buttons
const awaitingBacks = ref(false);

async function printBacks(): Promise<void> {
    // Closed first, so it cannot offer "record the print" before the backs are printed
    confirming.value = false;
    sides.value = 'back';
    await waitForAssets();
    print();
}

async function logPrint(): Promise<void> {
    logging.value = true;
    noteError.value = [];
    const body = { method: 'browser', copies: copies.value, note: note.value.trim() || null };
    const cardIds = printable.value.map((entry) => entry.id);
    try {
        await (cardIds.length === 1 ? http.post(`/cards/${cardIds[0]}/prints`, body) : http.post('/cards/prints', { ...body, cards: cardIds }));
        logged.value += cardIds.length;
        confirming.value = false;
        note.value = '';
        ui.notify(t('cards.print.logged', { count: cardIds.length }));
    } catch (error) {
        const apiError = toApiError(error);
        noteError.value = apiError.errors.note ?? [];
        if (!apiError.errors.note) {
            ui.notify(apiError.message, 'error');
        }
    } finally {
        logging.value = false;
    }
}

function close(): void {
    if (window.opener) {
        window.close();

        return;
    }
    router.push({ name: 'cards' });
}

// One named @page per card size; the sheet uses it, and nothing else on the page prints
const pageStyle = document.createElement('style');
watchEffect(() => {
    pageStyle.textContent = [...pageNames.value]
        .map(([key, name]) => {
            const [width, height] = key.split('x');

            return `@page ${name} { size: ${width}mm ${height}mm; margin: 0; } [data-print-page="${name}"] { page: ${name}; }`;
        })
        .join('\n');
});

watchEffect(() => {
    copies.value = Math.min(Math.max(1, Math.round(Number(copies.value) || 1)), printOptions.value.max_copies);
});

onMounted(() => {
    document.head.append(pageStyle);
    window.addEventListener('afterprint', onAfterPrint);
    load();
});

onBeforeUnmount(() => {
    pageStyle.remove();
    window.removeEventListener('afterprint', onAfterPrint);
});
</script>

<template>
    <div class="min-h-full bg-surface-2" data-print-page-root>
        <header class="sticky top-0 z-10 border-b border-line bg-surface print:hidden" data-print-toolbar>
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-3 px-4 py-3">
                <div class="mr-auto">
                    <h1 class="text-base font-semibold text-fg">{{ t('cards.print.title') }}</h1>
                    <p class="text-xs text-muted" data-print-count>{{ t('cards.print.counts', { cards: printable.length, pages: sheets.length }) }}</p>
                </div>
                <label v-if="anyDoubleSided" class="flex items-center gap-2 text-sm text-muted">
                    {{ t('cards.print.sides') }}
                    <select v-model="sides" class="h-9 rounded-lg border border-line bg-surface px-2 text-sm text-fg" data-print-sides @change="waitForAssets">
                        <option v-for="choice in ['both', 'front', 'back']" :key="choice" :value="choice">{{ t(`cards.print.sideOptions.${choice}`) }}</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 text-sm text-muted">
                    {{ t('cards.print.copies') }}
                    <input v-model.number="copies" type="number" min="1" :max="printOptions.max_copies" class="h-9 w-16 rounded-lg border border-line bg-surface px-2 text-sm text-fg" data-print-copies />
                </label>
                <UiButton :disabled="!printable.length || !assetsReady" :loading="loading || (!!printable.length && !assetsReady)" data-print-now @click="print">
                    <Printer class="size-4" /> {{ t('cards.print.print') }}
                </UiButton>
                <UiButton variant="secondary" data-print-close @click="close"><X class="size-4" /> {{ t('common.close') }}</UiButton>
            </div>

            <div v-if="!loading" class="mx-auto max-w-5xl space-y-2 px-4 pb-3 text-sm">
                <p v-if="!ids.length" class="text-muted">{{ t('cards.print.none') }}</p>
                <p v-else-if="tooMany" class="text-danger" data-print-too-many>{{ t('cards.print.tooMany', { max: printOptions.max_batch }) }}</p>
                <div v-if="skipped.length" class="rounded-lg border border-danger/40 bg-danger/10 p-3 text-fg" data-print-skipped>
                    <p class="font-medium">{{ t('cards.print.skipped', { count: skipped.length }) }}</p>
                    <ul class="mt-1 list-disc pl-5 text-xs text-muted">
                        <li v-for="entry in skipped" :key="entry.id">{{ entry.card?.number ?? entry.id }}: {{ entry.problem }}</li>
                    </ul>
                </div>
                <div v-if="withMissing.length" class="rounded-lg border border-warning/40 bg-warning/10 p-3 text-fg" data-print-missing>
                    <p class="flex items-center gap-2 font-medium"><AlertTriangle class="size-4 text-warning" /> {{ t('cards.print.missingTitle') }}</p>
                    <ul class="mt-1 list-disc pl-5 text-xs text-muted">
                        <li v-for="entry in withMissing" :key="entry.id">
                            {{ entry.card!.number }} ({{ entry.card!.family_member?.name ?? entry.card!.cardholder?.full_name }}):
                            {{ entry.missing.map((key) => t(`cards.missingImage.${key}`)).join(', ') }}
                        </li>
                    </ul>
                    <p class="mt-1 text-xs text-muted">{{ t('cards.missingImageHint') }}</p>
                </div>
                <p v-if="logged" class="flex items-center gap-2 text-success" data-print-logged><CheckCircle2 class="size-4" /> {{ t('cards.print.loggedTotal', { count: logged }) }}</p>
            </div>
        </header>

        <div v-if="loading" class="flex justify-center py-16 print:hidden"><Loader2 class="size-6 animate-spin text-muted" /></div>
        <main v-else ref="sheetsEl" class="print-sheets flex flex-col items-center gap-6 px-4 py-8" data-print-sheets>
            <section
                v-for="sheet in sheets"
                :key="sheet.key"
                class="print-sheet"
                :data-print-page="sheet.page"
                :data-card="sheet.entry.id"
                :style="{ width: `${sheet.entry.layout!.size_mm.width}mm`, height: `${sheet.entry.layout!.size_mm.height}mm` }"
            >
                <CardRender :layout="sheet.entry.layout!" :values="sheet.entry.values!" :box="boxOf(sheet.entry.layout!)" :sides="[sheet.side]" />
            </section>
        </main>

        <UiDialog v-model:open="confirming" :title="t('cards.print.confirmTitle')" :description="awaitingBacks ? t('cards.print.backPending') : t('cards.print.confirmHint')">
            <UiTextarea v-if="!awaitingBacks" v-model="note" :label="t('cards.print.note')" :error="noteError" :maxlength="255" :rows="2" />
            <template #footer>
                <UiButton variant="ghost" data-print-not-printed @click="confirming = false">{{ t('cards.print.notPrinted') }}</UiButton>
                <UiButton variant="secondary" data-print-again @click="print">{{ t('cards.print.again') }}</UiButton>
                <UiButton v-if="awaitingBacks" data-print-backs @click="printBacks">{{ t('cards.print.printBacks') }}</UiButton>
                <UiButton v-else :loading="logging" data-print-confirm @click="logPrint">{{ t('cards.print.confirm') }}</UiButton>
            </template>
        </UiDialog>
    </div>
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

    [data-print-page-root] {
        background: none !important;
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
