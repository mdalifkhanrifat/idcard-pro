<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, CheckCircle2, FileDown, Loader2, Printer, X } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import CardSheets from './CardSheets.vue';
import { barcodeProblems, displayStatus, downloadCardsPdf, missingImages, printPages, SHEET_CARD, useCardReasons, type BarcodeProblem, type CardImageKey, type PrintSides, type SheetCard } from './cards';
import CardBarcodeProblems from './CardBarcodeProblems.vue';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import type { CardLayout, CardValues, IssuedCard } from '@/types';

/**
 * Prints issued cards at their real size (laid out by CardSheets: one page per card side, sized to the
 * card, or several cards on a paper sheet with crop marks). Opened with ?ids=a,b for one card or a batch.
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
    /** Barcodes that will print blank. */
    barcodes: BarcodeProblem[];
}

const PARALLEL = 6;
/** The page layout last used on this device (printers stay where they are). */
const SHEET_STORAGE_KEY = 'card-print-sheet';

const route = useRoute();
const router = useRouter();
const { t, te } = useI18n();
const ui = useUiStore();
const { printOptions, ready: optionsReady } = useCardReasons();

const entries = ref<Entry[]>([]);
const loading = ref(true);
const assetsReady = ref(false);
const copies = ref(1);
const sides = ref<PrintSides>('both');
const sheetKey = ref(readSheet());
const confirming = ref(false);
const note = ref('');
const noteError = ref<string[]>([]);
const logging = ref(false);
const logged = ref(0);
const downloading = ref(false);
const recordPdf = ref(false);
const sheetsRef = ref<InstanceType<typeof CardSheets> | null>(null);

const ids = computed(() => [...new Set(String(route.query.ids ?? '').split(',').map((id) => id.trim()).filter(Boolean))]);
const tooMany = computed(() => ids.value.length > printOptions.value.max_batch);
const printable = computed(() => entries.value.filter((entry) => !entry.problem));
const skipped = computed(() => entries.value.filter((entry) => entry.problem));
const withMissing = computed(() => printable.value.filter((entry) => entry.missing.length));
const withBarcodeProblems = computed(() => printable.value.filter((entry) => entry.barcodes.length));
const anyDoubleSided = computed(() => printable.value.some((entry) => entry.layout?.is_double_sided));

const sheetCards = computed<SheetCard[]>(() => printable.value.map((entry) => ({ id: entry.id, layout: entry.layout!, values: entry.values! })));
/** The chosen paper; null = one card side per page (also when a remembered paper no longer exists). */
const sheet = computed(() => printOptions.value.sheets.find((size) => size.key === sheetKey.value) ?? null);
const pageCount = computed(() => printPages(sheetCards.value, sides.value, copies.value, sheet.value).length);
const paperLabel = (key: string) => (te(`cards.print.papers.${key}`) ? t(`cards.print.papers.${key}`) : key.toUpperCase());

function readSheet(): string {
    try {
        return localStorage.getItem(SHEET_STORAGE_KEY) ?? SHEET_CARD;
    } catch {
        return SHEET_CARD;
    }
}

async function changeSheet(): Promise<void> {
    try {
        localStorage.setItem(SHEET_STORAGE_KEY, sheetKey.value);
    } catch {
        // Not remembered (e.g. private window); the choice still applies now
    }
    await waitForAssets();
}

async function loadEntry(id: string): Promise<Entry> {
    try {
        const { data } = await http.get(`/cards/${id}`);
        const card: IssuedCard = data.data;
        const problem = card.printable ? null : t('cards.print.notPrintable', { status: t(`cards.statuses.${displayStatus(card)}`) });

        return { id, card, layout: data.meta.layout, values: data.meta.values, problem, missing: missingImages(data.meta.layout, data.meta.values), barcodes: barcodeProblems(data.meta.layout, data.meta.values) };
    } catch (error) {
        const apiError = toApiError(error);
        const problem = apiError.status === 404 ? t('cards.print.notFound') : apiError.message;

        return { id, card: null, layout: null, values: null, problem, missing: [], barcodes: [] };
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
    if (printable.value.length && !skipped.value.length && !withMissing.value.length && !withBarcodeProblems.value.length) {
        print();
    }
}

async function waitForAssets(): Promise<void> {
    assetsReady.value = false;
    // The sheets are drawn on the next render after the cards load
    await nextTick();
    await sheetsRef.value?.waitForAssets();
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

/** The same cards as a PDF made on the server: no print dialog, so it also works on phones. */
async function downloadPdf(): Promise<void> {
    downloading.value = true;
    const cardIds = printable.value.map((entry) => entry.id);
    try {
        await downloadCardsPdf(cardIds, { sides: sides.value, record: recordPdf.value, sheet: sheet.value?.key ?? SHEET_CARD });
        if (recordPdf.value) {
            logged.value += cardIds.length;
            ui.notify(t('cards.print.pdfRecorded', { count: cardIds.length }));
        } else {
            ui.notify(t('cards.print.pdfDone'));
        }
    } catch (error) {
        ui.notify((error as Error).message, 'error');
    } finally {
        downloading.value = false;
    }
}

function close(): void {
    if (window.opener) {
        window.close();

        return;
    }
    router.push({ name: 'cards' });
}

watchEffect(() => {
    copies.value = Math.min(Math.max(1, Math.round(Number(copies.value) || 1)), printOptions.value.max_copies);
});

onMounted(() => {
    window.addEventListener('afterprint', onAfterPrint);
    load();
});

onBeforeUnmount(() => {
    window.removeEventListener('afterprint', onAfterPrint);
});
</script>

<template>
    <div class="min-h-full bg-surface-2 print:bg-transparent" data-print-page-root>
        <header class="sticky top-0 z-10 border-b border-line bg-surface print:hidden" data-print-toolbar>
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-3 px-4 py-3">
                <div class="mr-auto">
                    <h1 class="text-base font-semibold text-fg">{{ t('cards.print.title') }}</h1>
                    <p class="text-xs text-muted" data-print-count>{{ t('cards.print.counts', { cards: printable.length, pages: pageCount }) }}</p>
                </div>
                <label v-if="anyDoubleSided" class="flex items-center gap-2 text-sm text-muted">
                    {{ t('cards.print.sides') }}
                    <select v-model="sides" class="h-9 rounded-lg border border-line bg-surface px-2 text-sm text-fg" data-print-sides @change="waitForAssets">
                        <option v-for="choice in ['both', 'front', 'back']" :key="choice" :value="choice">{{ t(`cards.print.sideOptions.${choice}`) }}</option>
                    </select>
                </label>
                <label v-if="printOptions.sheets.length" class="flex items-center gap-2 text-sm text-muted">
                    {{ t('cards.print.sheet') }}
                    <select v-model="sheetKey" class="h-9 rounded-lg border border-line bg-surface px-2 text-sm text-fg" data-print-sheet @change="changeSheet">
                        <option :value="SHEET_CARD">{{ t('cards.print.sheetCard') }}</option>
                        <option v-for="size in printOptions.sheets" :key="size.key" :value="size.key">{{ t('cards.print.sheetPaper', { paper: paperLabel(size.key) }) }}</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 text-sm text-muted">
                    {{ t('cards.print.copies') }}
                    <input v-model.number="copies" type="number" min="1" :max="printOptions.max_copies" class="h-9 w-16 rounded-lg border border-line bg-surface px-2 text-sm text-fg" data-print-copies />
                </label>
                <UiButton :disabled="!printable.length || !assetsReady" :loading="loading || (!!printable.length && !assetsReady)" data-print-now @click="print">
                    <Printer class="size-4" /> {{ t('cards.print.print') }}
                </UiButton>
                <template v-if="printOptions.pdf">
                    <UiButton variant="secondary" :disabled="!printable.length || loading" :loading="downloading" data-print-pdf @click="downloadPdf">
                        <FileDown class="size-4" /> {{ t('cards.print.pdf') }}
                    </UiButton>
                    <UiCheckbox v-model="recordPdf" :label="t('cards.print.pdfRecord')" data-print-pdf-record />
                </template>
                <UiButton variant="secondary" data-print-close @click="close"><X class="size-4" /> {{ t('common.close') }}</UiButton>
            </div>

            <div v-if="!loading" class="mx-auto max-w-5xl space-y-2 px-4 pb-3 text-sm">
                <p v-if="!ids.length" class="text-muted">{{ t('cards.print.none') }}</p>
                <p v-else-if="tooMany" class="text-danger" data-print-too-many>{{ t('cards.print.tooMany', { max: printOptions.max_batch }) }}</p>
                <p v-if="sheet && printable.length" class="text-xs text-muted" data-print-sheet-hint>{{ t('cards.print.sheetHint') }}</p>
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
                <div v-if="withBarcodeProblems.length" class="rounded-lg border border-warning/40 bg-warning/10 p-3 text-fg" data-print-barcodes>
                    <p class="flex items-center gap-2 font-medium"><AlertTriangle class="size-4 text-warning" /> {{ t('cards.barcode.title') }}</p>
                    <ul class="mt-1 list-disc pl-5 text-xs text-muted">
                        <li v-for="entry in withBarcodeProblems" :key="entry.id">
                            {{ entry.card!.number }} ({{ entry.card!.family_member?.name ?? entry.card!.cardholder?.full_name }}):
                            <CardBarcodeProblems :problems="entry.barcodes" />
                        </li>
                    </ul>
                    <p class="mt-1 text-xs text-muted">{{ t('cards.barcode.hint') }}</p>
                </div>
                <p v-if="logged" class="flex items-center gap-2 text-success" data-print-logged><CheckCircle2 class="size-4" /> {{ t('cards.print.loggedTotal', { count: logged }) }}</p>
            </div>
        </header>

        <div v-if="loading" class="flex justify-center py-16 print:hidden"><Loader2 class="size-6 animate-spin text-muted" /></div>
        <CardSheets v-else ref="sheetsRef" :cards="sheetCards" :sides="sides" :copies="copies" :sheet="sheet" />

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
