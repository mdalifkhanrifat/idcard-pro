<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Columns3, FileDown, RotateCcw, Search, SlidersHorizontal } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import CardDetailDialog from '@/pages/cards/CardDetailDialog.vue';
import { useCardsPrinted } from '@/pages/cards/cards';
import ReportColumnsDialog from './ReportColumnsDialog.vue';
import ReportTable from './ReportTable.vue';
import { downloadReport, useChosenColumns, useReports, type ReportValue } from './reports';
import { useListQuery, type PageMeta } from '@/composables/useListQuery';
import { useMasterDataOptions } from '@/composables/useMasterDataOptions';
import { http, toApiError } from '@/lib/http';
import { formatNumber } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { CardTemplate } from '@/types';

/**
 * Card print history (legacy "Card Print History"): every printing of every card, with the columns the
 * viewer (or the organization) chose, totals over every page, and downloads.
 */
interface PrintRow {
    id: string;
    card: { id: string; number: string };
    cardholder: { id: string; full_name: string };
    family_member: { id: string; name: string } | null;
    values?: Record<string, ReportValue>;
}

const REPORT = 'card_prints';
/** Master data a printing can be filtered by, when the organization keeps such a list. */
const LOOKUP_FILTERS = ['department', 'unit', 'rank', 'branch', 'designation', 'trade', 'appointment'] as const;

const { t, n } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const lookups = useMasterDataOptions();
const registry = useReports();
const report = computed(() => registry.find(REPORT));
const chosen = useChosenColumns(report);
const timeZone = computed(() => auth.tenant?.timezone);

const list = useListQuery<PrintRow, PageMeta & { totals?: { prints: number; copies: number; cards: number } }>('/reports/card-prints', {
    filters: { search: '', printed_from: '', printed_to: '', template: '', method: '', printed_by: '', holder: '', ...Object.fromEntries(LOOKUP_FILTERS.map((key) => [key, ''])) },
    sort: '-printed_at',
    extraParams: () => ({ columns: chosen.keys.value }),
});
const { filters, rows, meta, loading, sort, page } = list;

const showFilters = ref(false);
const activeFilterCount = computed(() => Object.entries(filters).filter(([key, value]) => key !== 'search' && value !== '').length);
const templates = ref<CardTemplate[]>([]);
const users = ref<{ id: string; name: string }[]>([]);
const lookupFilters = computed(() => LOOKUP_FILTERS.filter((type) => type === 'unit' || lookups.options(type).length));

// A unit only makes sense inside the chosen department
watch(() => filters.department, (department, previous) => {
    if (previous !== undefined && department !== previous) {
        filters.unit = '';
    }
});
// Other columns: the same rows with other values (not the first columns, which come with the first load)
watch(() => chosen.keys.value.join(), (keys, previous) => {
    if (previous && keys !== previous) {
        list.load();
    }
});

onMounted(async () => {
    lookups.load([...LOOKUP_FILTERS]);
    await registry.load().catch((error) => ui.notify(toApiError(error).message, 'error'));
    list.load();
    templates.value = (await http.get('/card-templates', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } }))).data.data;
    if (auth.can('users.view')) {
        users.value = (await http.get('/users', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } }))).data.data;
    }
});

const totals = computed(() => meta.value.totals ?? null);
const TOTALS = ['prints', 'copies', 'cards'] as const;

const columnsOpen = ref(false);
const downloading = ref<string | null>(null);
const tooMany = computed(() => report.value?.max_export_rows != null && meta.value.total > report.value.max_export_rows);

async function download(format: string): Promise<void> {
    downloading.value = format;
    try {
        await downloadReport(REPORT, format, list.queryParams(), chosen.keys.value);
    } catch (error) {
        ui.notify((error as Error).message, 'error');
    } finally {
        downloading.value = null;
    }
}

const detailOpen = ref(false);
const detailId = ref<string | null>(null);
function showCard(id: string): void {
    detailId.value = id;
    detailOpen.value = true;
}

// Printed in the print page's tab: new rows
useCardsPrinted(() => list.load());
</script>

<template>
    <div>
        <UiPageHeader :title="report?.name ?? t('reports.names.card_prints')" :subtitle="t('reports.descriptions.card_prints')">
            <UiButton variant="secondary" :disabled="!report" data-open-columns @click="columnsOpen = true">
                <Columns3 class="size-4" />{{ t('reports.columns.button') }}
                <UiBadge v-if="chosen.isOwn.value" tone="brand">{{ n(chosen.keys.value.length) }}</UiBadge>
            </UiButton>
            <UiButton
                v-for="format in report?.formats ?? []"
                :key="format"
                variant="secondary"
                :disabled="tooMany || !meta.total"
                :loading="downloading === format"
                :title="tooMany ? t('reports.download.tooMany', { max: formatNumber(report?.max_export_rows ?? 0) }) : undefined"
                :data-download="format"
                @click="download(format)"
            >
                <FileDown class="size-4" />{{ t(`reports.download.${format}`) }}
            </UiButton>
        </UiPageHeader>

        <!-- Totals over every page -->
        <!-- As many per row as fit, so large counts are never cut on phones -->
        <div class="mb-4 grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-3">
            <div v-for="key in TOTALS" :key="key" class="min-w-0 rounded-xl border border-line bg-surface p-4" :data-total="key">
                <span class="block text-xs font-medium text-muted">{{ t(`reports.totals.${key}`) }}</span>
                <span class="mt-1 block text-xl font-semibold break-all text-fg tabular-nums sm:text-2xl">{{ totals ? n(totals[key]) : '—' }}</span>
            </div>
        </div>

        <div class="rounded-xl border border-line bg-surface">
            <!-- Toolbar -->
            <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('reports.cardPrints.searchPlaceholder')"
                        class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                        data-report-search
                    />
                </div>
                <UiButton variant="secondary" @click="showFilters = !showFilters">
                    <SlidersHorizontal class="size-4" />{{ t('common.filters') }}
                    <UiBadge v-if="activeFilterCount" tone="brand">{{ activeFilterCount }}</UiBadge>
                </UiButton>
                <UiButton v-if="activeFilterCount || filters.search" variant="ghost" @click="list.resetFilters()"><RotateCcw class="size-4" />{{ t('common.reset') }}</UiButton>
            </div>
            <div v-if="showFilters" class="grid gap-4 border-b border-line bg-surface-2/50 p-4 sm:grid-cols-2 lg:grid-cols-4" data-report-filters>
                <UiInput v-model="filters.printed_from" type="date" :label="`${t('cards.printedOn')} · ${t('common.from')}`" data-filter="printed_from" />
                <UiInput v-model="filters.printed_to" type="date" :label="`${t('cards.printedOn')} · ${t('common.to')}`" data-filter="printed_to" />
                <UiSelect v-model="filters.method" :label="t('reports.cardPrints.method')" :placeholder="t('common.all')" :options="(['browser', 'pdf', 'import'] as const).map((value) => ({ value, label: t(`reports.cardPrints.methods.${value}`) }))" />
                <UiSelect v-if="users.length" v-model="filters.printed_by" :label="t('reports.cardPrints.printedBy')" :placeholder="t('common.all')" :options="users.map((user) => ({ value: user.id, label: user.name }))" />
                <UiSelect v-model="filters.template" :label="t('cards.template')" :placeholder="t('common.all')" :options="templates.map((template) => ({ value: template.id, label: `${template.name} (${template.code})` }))" />
                <UiSelect v-model="filters.holder" :label="t('cards.holder')" :placeholder="t('common.all')" :options="[{ value: 'cardholder', label: t('cards.self') }, { value: 'family', label: t('cards.familyCards') }]" />
                <template v-for="type in lookupFilters" :key="type">
                    <UiSelect
                        v-if="type === 'unit'"
                        v-model="filters.unit"
                        :label="t('cardholders.fields.unit_id')"
                        :placeholder="filters.department ? t('common.all') : t('cardholders.selectDepartmentFirst')"
                        :options="filters.department ? lookups.options('unit', filters.department) : []"
                    />
                    <UiSelect v-else v-model="filters[type]" :label="t(`cardholders.fields.${type}_id`)" :placeholder="t('common.all')" :options="lookups.options(type)" />
                </template>
            </div>

            <ReportTable v-if="rows.length" :columns="chosen.columns.value" :rows="rows" :loading="loading" :sort="sort" :sortable="{ printed_at: 'printed_at', copies: 'copies' }" :time-zone="timeZone" @toggle-sort="list.toggleSort">
                <template #cell="{ column, row, text }">
                    <RouterLink
                        v-if="column.key === 'holder.name' && auth.can('cardholders.view')"
                        :to="{ path: `/cardholders/${(row as PrintRow).cardholder.id}`, query: { tab: 'cards' } }"
                        class="font-medium text-fg hover:underline"
                    >
                        {{ text }}
                    </RouterLink>
                    <button v-else-if="column.key === 'card.number' && auth.can('cards.view')" type="button" class="font-mono whitespace-nowrap text-fg hover:underline" data-card-number @click="showCard((row as PrintRow).card.id)">
                        {{ text }}
                    </button>
                    <template v-else>{{ text }}</template>
                </template>
            </ReportTable>
            <UiEmpty v-else-if="!loading" :title="t('reports.cardPrints.empty')" :hint="activeFilterCount || filters.search ? t('common.noResultsHint') : t('reports.cardPrints.emptyHint')" />
            <UiPagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :from="meta.from" :to="meta.to" @change="page = $event" />
        </div>

        <ReportColumnsDialog v-if="report" v-model:open="columnsOpen" :report="report" :chosen="chosen.keys.value" @apply="chosen.choose" @saved="registry.replace" />
        <CardDetailDialog v-model:open="detailOpen" :card-id="detailId" @changed="list.load()" />
    </div>
</template>
