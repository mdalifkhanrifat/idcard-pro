<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { FileDown, Printer, RotateCcw, Search, SlidersHorizontal, X } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiSortHeader from '@/components/ui/UiSortHeader.vue';
import CardDetailDialog from './CardDetailDialog.vue';
import CardPrintButton from './CardPrintButton.vue';
import { displayStatus, downloadCardsPdf, openPrintPage, statusTone, useCardReasons } from './cards';
import { useListQuery } from '@/composables/useListQuery';
import { useMasterDataOptions } from '@/composables/useMasterDataOptions';
import { http } from '@/lib/http';
import { formatDate, formatDateTime } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { CardTemplate, IssuedCard } from '@/types';

/**
 * Every issued card (legacy "Card Print History" and "Expired Card" reports): counts at the top,
 * which also filter the list, and search by number, RF number or person.
 */
const { t, te, n } = useI18n();
const lookups = useMasterDataOptions();
const cardReasons = useCardReasons();

/** Expiring = within this many days. */
const EXPIRING_DAYS = 30;

const list = useListQuery<IssuedCard>('/cards', {
    filters: { search: '', status: '', validity: '', expiring_days: '', reason: '', template: '', holder: '', department: '', unit: '', issued_from: '', issued_to: '', printed: '', printed_from: '', printed_to: '' },
    sort: '-created_at',
});
const { filters, rows, meta, loading, sort, page } = list;

const summary = ref<{ active: number; expired: number; expiring: number; revoked: number; pending: number } | null>(null);
const templates = ref<CardTemplate[]>([]);
const showFilters = ref(false);
const activeFilterCount = computed(() => Object.entries(filters).filter(([key, value]) => key !== 'search' && key !== 'expiring_days' && value !== '').length);
const reasonLabel = (key: string) => (te(`cards.reasons.${key}`) ? t(`cards.reasons.${key}`) : key);

/** Tiles: a click shows those cards (again: all cards). */
const TILES = [
    { key: 'active', tone: 'text-success', apply: { status: 'active', validity: '' } },
    { key: 'expired', tone: 'text-warning', apply: { status: '', validity: 'expired' } },
    // Brand colours can be too dark in dark mode, so the neutral text colour is used here
    { key: 'expiring', tone: 'text-fg', apply: { status: '', validity: 'expiring', expiring_days: String(EXPIRING_DAYS) } },
    { key: 'revoked', tone: 'text-danger', apply: { status: 'revoked', validity: '' } },
    // Valid cards never printed (legacy "PENDING")
    { key: 'pending', tone: 'text-fg', apply: { status: '', validity: 'valid', printed: 'pending' } },
] as const;
const TILE_KEYS = ['status', 'validity', 'expiring_days', 'printed'] as const;
const tileActive = (tile: (typeof TILES)[number]) =>
    TILE_KEYS.every((key) => (filters[key] ?? '') === ((tile.apply as Record<string, string>)[key] ?? '') || (key === 'expiring_days' && tile.key !== 'expiring'));

function applyTile(tile: (typeof TILES)[number]): void {
    const on = tileActive(tile);
    for (const key of TILE_KEYS) {
        filters[key] = '';
    }
    if (!on) {
        Object.assign(filters, tile.apply);
    }
}

// A unit only makes sense inside the chosen department.
watch(() => filters.department, (department, previous) => {
    if (previous !== undefined && department !== previous) {
        filters.unit = '';
    }
});

async function loadSummary(): Promise<void> {
    summary.value = (await http.get('/cards/summary', { params: { expiring_days: EXPIRING_DAYS } })).data.data;
}

onMounted(async () => {
    lookups.load(['department', 'unit']);
    list.load();
    loadSummary();
    templates.value = (await http.get('/card-templates', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } }))).data.data;
});

const detailOpen = ref(false);
const detailId = ref<string | null>(null);

function show(card: IssuedCard): void {
    detailId.value = card.id;
    detailOpen.value = true;
}

function changed(): void {
    list.load();
    loadSummary();
}

/**
 * Batch printing: printable cards picked from the list, kept across pages and filters so cards can be
 * gathered from several pages (e.g. the "not printed yet" tile → select all → print). In picking order.
 */
const auth = useAuthStore();
const ui = useUiStore();
const canPrint = computed(() => auth.can('cards.print'));
const selected = ref<string[]>([]);
const printableRows = computed(() => rows.value.filter((card) => card.printable));
const pageSelected = computed(() => printableRows.value.filter((card) => selected.value.includes(card.id)).length);
const tooManySelected = computed(() => selected.value.length > cardReasons.printOptions.value.max_batch);
const downloading = ref(false);
/** The print page was opened in another tab: refresh counts when the user comes back. */
let printTabOpen = false;

function toggleCard(card: IssuedCard): void {
    selected.value = selected.value.includes(card.id) ? selected.value.filter((id) => id !== card.id) : [...selected.value, card.id];
}

function togglePage(): void {
    const pageIds = printableRows.value.map((card) => card.id);
    selected.value =
        pageSelected.value === pageIds.length
            ? selected.value.filter((id) => !pageIds.includes(id))
            : [...selected.value, ...pageIds.filter((id) => !selected.value.includes(id))];
}

function printSelected(): void {
    openPrintPage(selected.value);
    printTabOpen = true;
}

async function downloadSelected(): Promise<void> {
    downloading.value = true;
    try {
        await downloadCardsPdf(selected.value, { sides: 'both', record: false });
        ui.notify(t('cards.print.pdfDone'));
    } catch (error) {
        ui.notify((error as Error).message, 'error');
    } finally {
        downloading.value = false;
    }
}

function onFocus(): void {
    if (printTabOpen) {
        printTabOpen = false;
        changed();
    }
}

onMounted(() => window.addEventListener('focus', onFocus));
onBeforeUnmount(() => window.removeEventListener('focus', onFocus));
</script>

<template>
    <div>
        <UiPageHeader :title="t('cards.pageTitle')" :subtitle="t('cards.pageSubtitle')" />

        <!-- Counts -->
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <button
                v-for="tile in TILES"
                :key="tile.key"
                type="button"
                class="rounded-xl border bg-surface p-4 text-left transition hover:border-brand"
                :class="tileActive(tile) ? 'border-brand ring-2 ring-[var(--ring)]' : 'border-line'"
                :aria-pressed="tileActive(tile)"
                :data-tile="tile.key"
                @click="applyTile(tile)"
            >
                <span class="block text-xs font-medium text-muted">{{ t(`cards.summary.${tile.key}`, { days: n(EXPIRING_DAYS) }) }}</span>
                <span class="mt-1 block text-2xl font-semibold tabular-nums" :class="tile.tone">{{ summary ? summary[tile.key] : '—' }}</span>
            </button>
        </div>

        <div class="rounded-xl border border-line bg-surface">
            <!-- Toolbar -->
            <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('cards.searchPlaceholder')"
                        class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                        data-card-search
                    />
                </div>
                <UiButton variant="secondary" @click="showFilters = !showFilters">
                    <SlidersHorizontal class="size-4" />{{ t('common.filters') }}
                    <UiBadge v-if="activeFilterCount" tone="brand">{{ activeFilterCount }}</UiBadge>
                </UiButton>
                <UiButton v-if="activeFilterCount || filters.search" variant="ghost" @click="list.resetFilters()"><RotateCcw class="size-4" />{{ t('common.reset') }}</UiButton>
            </div>
            <div v-if="showFilters" class="grid gap-4 border-b border-line bg-surface-2/50 p-4 sm:grid-cols-2 lg:grid-cols-4" data-card-filters>
                <UiSelect v-model="filters.status" :label="t('common.status')" :placeholder="t('common.all')" :options="(['active', 'replaced', 'revoked'] as const).map((value) => ({ value, label: t(`cards.statuses.${value}`) }))" />
                <UiSelect
                    v-model="filters.validity"
                    :label="t('cards.validityFilter')"
                    :placeholder="t('common.all')"
                    :options="(['valid', 'expiring', 'expired'] as const).map((value) => ({ value, label: t(`cards.validityOptions.${value}`, { days: n(Number(filters.expiring_days) || EXPIRING_DAYS) }) }))"
                />
                <UiInput v-if="filters.validity === 'expiring'" v-model="filters.expiring_days" type="number" :label="t('cards.expiringDays')" :placeholder="String(EXPIRING_DAYS)" />
                <UiSelect v-model="filters.reason" :label="t('cards.reason')" :placeholder="t('common.all')" :options="cardReasons.reasons.value.map((reason) => ({ value: reason.key, label: reasonLabel(reason.key) }))" />
                <UiSelect v-model="filters.template" :label="t('cards.template')" :placeholder="t('common.all')" :options="templates.map((template) => ({ value: template.id, label: `${template.name} (${template.code})` }))" />
                <UiSelect v-model="filters.holder" :label="t('cards.holder')" :placeholder="t('common.all')" :options="[{ value: 'cardholder', label: t('cards.self') }, { value: 'family', label: t('cards.familyCards') }]" />
                <UiSelect v-model="filters.department" :label="t('cardholders.fields.department_id')" :placeholder="t('common.all')" :options="lookups.options('department')" />
                <UiSelect
                    v-model="filters.unit"
                    :label="t('cardholders.fields.unit_id')"
                    :placeholder="filters.department ? t('common.all') : t('cardholders.selectDepartmentFirst')"
                    :options="filters.department ? lookups.options('unit', filters.department) : []"
                />
                <UiInput v-model="filters.issued_from" type="date" :label="`${t('cards.issueDate')} · ${t('common.from')}`" />
                <UiInput v-model="filters.issued_to" type="date" :label="`${t('cards.issueDate')} · ${t('common.to')}`" />
                <UiSelect v-model="filters.printed" :label="t('cards.printedFilter')" :placeholder="t('common.all')" :options="(['pending', 'printed'] as const).map((value) => ({ value, label: t(`cards.printedOptions.${value}`) }))" data-filter-printed />
                <UiInput v-model="filters.printed_from" type="date" :label="`${t('cards.printedOn')} · ${t('common.from')}`" />
                <UiInput v-model="filters.printed_to" type="date" :label="`${t('cards.printedOn')} · ${t('common.to')}`" />
            </div>

            <!-- Batch printing -->
            <div v-if="canPrint && selected.length" class="flex flex-wrap items-center gap-3 border-b border-line bg-surface-2/50 px-4 py-3" data-selection-bar>
                <span class="text-sm font-medium text-fg" data-selected-count>{{ t('cards.batch.selected', { count: n(selected.length) }) }}</span>
                <span v-if="tooManySelected" class="text-sm text-danger" data-selection-too-many>{{ t('cards.print.tooMany', { max: n(cardReasons.printOptions.value.max_batch) }) }}</span>
                <div class="ml-auto flex flex-wrap gap-2">
                    <UiButton size="sm" :disabled="tooManySelected" data-print-selected @click="printSelected"><Printer class="size-4" />{{ t('cards.batch.print') }}</UiButton>
                    <UiButton v-if="cardReasons.printOptions.value.pdf" variant="secondary" size="sm" :disabled="tooManySelected" :loading="downloading" data-pdf-selected @click="downloadSelected">
                        <FileDown class="size-4" />{{ t('cards.batch.pdf') }}
                    </UiButton>
                    <UiButton variant="ghost" size="sm" data-clear-selection @click="selected = []"><X class="size-4" />{{ t('cards.batch.clear') }}</UiButton>
                </div>
            </div>

            <!-- Table -->
            <!-- relative: the hidden (sr-only) header label is absolutely positioned; without it, it escapes the scroll box and widens the page on phones -->
            <div v-if="rows.length" class="relative overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs font-medium tracking-wide whitespace-nowrap text-muted uppercase">
                        <tr class="border-b border-line">
                            <th v-if="canPrint" class="w-px py-3 pr-1 pl-5">
                                <input
                                    type="checkbox"
                                    class="size-4 accent-[var(--brand)] disabled:opacity-40"
                                    :checked="printableRows.length > 0 && pageSelected === printableRows.length"
                                    :indeterminate="pageSelected > 0 && pageSelected < printableRows.length"
                                    :disabled="!printableRows.length"
                                    :aria-label="t('cards.batch.selectPage')"
                                    :title="t('cards.batch.selectPage')"
                                    data-select-page
                                    @change="togglePage"
                                />
                            </th>
                            <th class="px-5 py-3"><UiSortHeader field="number" :sort="sort" :label="t('cards.number')" @toggle="list.toggleSort" /></th>
                            <th class="px-5 py-3">{{ t('cards.holder') }}</th>
                            <th class="px-5 py-3">{{ t('cards.template') }}</th>
                            <th class="px-5 py-3">{{ t('cards.reason') }}</th>
                            <th class="px-5 py-3"><UiSortHeader field="issue_date" :sort="sort" :label="t('cards.issueDate')" @toggle="list.toggleSort" /></th>
                            <th class="px-5 py-3"><UiSortHeader field="expiry_date" :sort="sort" :label="t('cards.expiryDate')" @toggle="list.toggleSort" /></th>
                            <th class="px-5 py-3">{{ t('common.status') }}</th>
                            <th class="px-3 py-3"><span class="sr-only">{{ t('common.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody :class="loading && 'opacity-50'">
                        <tr
                            v-for="card in rows"
                            :key="card.id"
                            class="cursor-pointer border-b border-line last:border-0 hover:bg-surface-2"
                            :class="selected.includes(card.id) && 'bg-surface-2'"
                            data-card-row
                            @click="show(card)"
                        >
                            <td v-if="canPrint" class="py-3 pr-1 pl-5" @click.stop>
                                <input
                                    type="checkbox"
                                    class="size-4 accent-[var(--brand)] disabled:opacity-40"
                                    :checked="selected.includes(card.id)"
                                    :disabled="!card.printable"
                                    :aria-label="t('cards.batch.selectCard', { number: card.number })"
                                    :title="card.printable ? t('cards.batch.selectCard', { number: card.number }) : t('cards.batch.notPrintable')"
                                    data-select-card
                                    @change="toggleCard(card)"
                                />
                            </td>
                            <td class="px-5 py-3 font-mono whitespace-nowrap text-fg">{{ card.number }}</td>
                            <td class="px-5 py-3">
                                <RouterLink :to="{ path: `/cardholders/${card.cardholder?.id}`, query: { tab: 'cards' } }" class="font-medium text-fg hover:underline" @click.stop>
                                    {{ card.family_member ? card.family_member.name : card.cardholder?.full_name }}
                                </RouterLink>
                                <p class="text-xs text-muted">
                                    <template v-if="card.family_member">{{ t('cards.familyOf', { name: card.cardholder?.full_name }) }}</template>
                                    <template v-else>{{ [card.cardholder?.employee_no, card.cardholder?.department].filter(Boolean).join(' · ') }}</template>
                                </p>
                            </td>
                            <td class="px-5 py-3 text-muted">{{ card.template?.name }}</td>
                            <td class="px-5 py-3 text-muted">{{ reasonLabel(card.reason) }}</td>
                            <td class="px-5 py-3 whitespace-nowrap text-muted">{{ formatDate(card.issue_date) }}</td>
                            <td class="px-5 py-3 whitespace-nowrap text-muted">{{ card.expiry_date ? formatDate(card.expiry_date) : t('cards.noExpiry') }}</td>
                            <td class="px-5 py-3">
                                <UiBadge :tone="statusTone(displayStatus(card))">{{ t(`cards.statuses.${displayStatus(card)}`) }}</UiBadge>
                                <p class="mt-1 text-xs whitespace-nowrap" :class="card.printable && !card.print_count ? 'text-warning' : 'text-muted'" :title="card.last_printed_at ? formatDateTime(card.last_printed_at) : undefined" data-print-state>
                                    {{ card.print_count ? t('cards.printedTimes', { count: card.print_count }) : t('cards.notPrinted') }}
                                </p>
                            </td>
                            <td class="px-3 py-2 text-right"><CardPrintButton :card="card" compact /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <UiEmpty v-else-if="!loading" :title="t('cards.listEmpty')" :hint="t('cards.listEmptyHint')" />
            <UiPagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :from="meta.from" :to="meta.to" @change="page = $event" />
        </div>

        <CardDetailDialog v-model:open="detailOpen" :card-id="detailId" @changed="changed" />
    </div>
</template>
