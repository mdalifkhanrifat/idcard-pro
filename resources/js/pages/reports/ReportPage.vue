<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ListPagination from '@/components/ListPagination.vue';
import { Columns3, RotateCcw, Search, SlidersHorizontal, X } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import CardDetailDialog from '@/pages/cards/CardDetailDialog.vue';
import { useCardsPrinted } from '@/pages/cards/cards';
import ReportColumnsDialog from './ReportColumnsDialog.vue';
import ReportDownloads from './ReportDownloads.vue';
import ReportTable from './ReportTable.vue';
import { reportSlug, useChosenColumns, useReports, type ReportFilterField, type ReportValue } from './reports';
import { useListQuery, type PageMeta } from '@/composables/useListQuery';
import { useMasterDataOptions } from '@/composables/useMasterDataOptions';
import { formatNumber } from '@/lib/format';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { Cardholder, CardTemplate } from '@/types';

/**
 * Any report of the server's registry: its filters, totals, sortable columns and download formats all come
 * from the report's description (GET /reports), so a new report needs no page of its own.
 */
interface ReportRow {
    id: string;
    card?: { id: string };
    cardholder?: { id: string };
    values?: Record<string, ReportValue>;
}

const props = defineProps<{ reportKey: string }>();

const { t, n } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const lookups = useMasterDataOptions();
const registry = useReports();
const report = computed(() => registry.find(props.reportKey));
/** Not in the user's registry: an unknown report, or one the user may not see (e.g. an old link). */
const unavailable = ref(false);
const chosen = useChosenColumns(report);
const timeZone = computed(() => auth.tenant?.timezone);

const list = useListQuery<ReportRow, PageMeta & { totals?: Record<string, number> }>(`/reports/${reportSlug(props.reportKey)}`, {
    filters: {},
    extraParams: () => ({ columns: chosen.keys.value }),
});
const { filters, rows, meta, loading, sort, page } = list;
/** The order shown: the one asked for, else the report's own. */
const currentSort = computed(() => sort.value || report.value?.default_sort || '');

const fields = computed<ReportFilterField[]>(() => report.value?.filters ?? []);
const searchField = computed(() => fields.value.find((field) => field.key === 'search') ?? null);
const panelFields = computed(() =>
    fields.value.filter((field) => {
        // A cardholder arrives from a link (e.g. their messages), not picked from a list of every cardholder
        if (field === searchField.value || field.type === 'cardholder' || (field.type === 'user' && !auth.can('users.view'))) {
            return false;
        }
        // Only while it means something (e.g. "within days" for cards expiring soon)
        const other = (key: string) => filters[key] || fields.value.find((candidate) => candidate.key === key)?.default || '';
        if (field.when && Object.entries(field.when).some(([key, value]) => other(key) !== value)) {
            return false;
        }
        // Master data lists the organization does not keep are left out (a child list shows with its parent)
        return field.type !== 'master_data' || !!field.parent || lookups.options(field.master_data ?? '').length > 0;
    }),
);
const showFilters = ref(false);
/** Criteria that came with a link (e.g. one cardholder's messages): shown as chips, not among the filters. */
const linkedFields = computed(() => fields.value.filter((field) => field.type === 'cardholder' && filters[field.key]));
const isLinked = (key: string) => fields.value.some((field) => field.key === key && field.type === 'cardholder');
const activeFilterCount = computed(() => Object.entries(filters).filter(([key, value]) => key !== 'search' && !isLinked(key) && value !== '').length);
const anyFilter = computed(() => activeFilterCount.value > 0 || !!filters.search || linkedFields.value.length > 0);
/** A linked cardholder's name and employee number, for those who may see cardholders. */
const cardholderNames = ref<Record<string, string>>({});
watch(
    () => linkedFields.value.map((field) => filters[field.key]),
    async (ids) => {
        if (!auth.can('cardholders.view')) {
            return;
        }
        for (const id of ids.filter((id) => id && !(id in cardholderNames.value))) {
            try {
                const { data } = await http.get(`/cardholders/${id}`);
                const holder = data.data as Pick<Cardholder, 'full_name' | 'employee_no'>;
                cardholderNames.value = { ...cardholderNames.value, [id]: holder.employee_no ? `${holder.full_name} (${holder.employee_no})` : holder.full_name };
            } catch {
                // Not found (e.g. deleted): the chip says "a cardholder"
            }
        }
    },
    { immediate: true },
);
const templates = ref<CardTemplate[]>([]);
const users = ref<{ id: string; name: string }[]>([]);

function optionsOf(field: ReportFilterField): { value: string; label: string }[] {
    switch (field.type) {
        case 'select':
            return field.options ?? [];
        case 'template':
            return templates.value.map((template) => ({ value: template.id, label: `${template.name} (${template.code})` }));
        case 'user':
            return users.value.map((user) => ({ value: user.id, label: user.name }));
        case 'master_data':
            return field.parent ? (filters[field.parent] ? lookups.options(field.master_data ?? '', filters[field.parent]) : []) : lookups.options(field.master_data ?? '');
        default:
            return [];
    }
}

const parentLabel = (field: ReportFilterField) => fields.value.find((other) => other.key === field.parent)?.label ?? '';

/** What an empty choice means: the report's default (e.g. "Expired"), else every row. */
function placeholderOf(field: ReportFilterField): string {
    if (field.parent && !filters[field.parent]) {
        return t('reports.filterInsideParent', { parent: parentLabel(field) });
    }

    return (field.default && field.options?.find((option) => option.value === field.default)?.label) || t('common.all');
}

// A child list (e.g. unit) only makes sense inside the chosen parent (department)
const childFields = computed(() => fields.value.filter((field) => field.parent));
watch(
    () => childFields.value.map((field) => filters[field.parent ?? ''] ?? ''),
    (now, before) => {
        childFields.value.forEach((field, index) => {
            if (before && before.length === now.length && now[index] !== before[index]) {
                filters[field.key] = '';
            }
        });
    },
);
// Other columns: the same rows with other values (not the first columns, which come with the first load)
watch(() => chosen.keys.value.join(), (keys, previous) => {
    if (previous && keys !== previous) {
        list.load();
    }
});

onMounted(async () => {
    try {
        await registry.load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');

        return;
    }
    if (!report.value) {
        unavailable.value = true;

        return;
    }
    list.addFilters(fields.value.map((field) => field.key));
    list.load();
    const types = fields.value.map((field) => field.type);
    lookups.load(fields.value.filter((field) => field.type === 'master_data' && field.master_data).map((field) => field.master_data as string));
    if (types.includes('template')) {
        templates.value = (await http.get('/card-templates', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } }))).data.data;
    }
    if (types.includes('user') && auth.can('users.view')) {
        users.value = (await http.get('/users', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } }))).data.data;
    }
});

function toggleSort(field: string): void {
    sort.value = currentSort.value === field ? `-${field}` : field;
}

const columnsOpen = ref(false);
const detailOpen = ref(false);
const detailId = ref<string | null>(null);
function showCard(id: string): void {
    detailId.value = id;
    detailOpen.value = true;
}

// A card printed or changed in another tab: rows may change
useCardsPrinted(() => {
    if (report.value) {
        list.load();
    }
});
</script>

<template>
    <div>
        <UiPageHeader :title="report?.name ?? t('nav.reports')" :subtitle="report?.description">
            <UiButton v-if="!unavailable" variant="secondary" :disabled="!report" data-open-columns @click="columnsOpen = true">
                <Columns3 class="size-4" />{{ t('reports.columns.button') }}
                <UiBadge v-if="chosen.isOwn.value" tone="brand">{{ n(chosen.keys.value.length) }}</UiBadge>
            </UiButton>
            <ReportDownloads :report-key="reportKey" :params="list.queryParams" :total="meta.total" :columns="chosen.keys.value" />
        </UiPageHeader>

        <div v-if="unavailable" class="rounded-xl border border-line bg-surface" data-report-unavailable>
            <UiEmpty :title="t('reports.unavailable')" :hint="t('reports.unavailableHint')">
                <RouterLink to="/reports" class="text-sm font-medium text-brand-ink hover:underline">{{ t('reports.allReports') }}</RouterLink>
            </UiEmpty>
        </div>

        <!-- Totals over every page; as many per row as fit, so large counts are never cut on phones -->
        <div v-if="report?.totals.length" class="mb-4 grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-3">
            <div v-for="total in report.totals" :key="total.key" class="min-w-0 rounded-xl border border-line bg-surface p-4" :data-total="total.key">
                <span class="block text-xs font-medium text-muted">{{ total.label }}</span>
                <span class="mt-1 block text-xl font-semibold break-all text-fg tabular-nums sm:text-2xl">{{ meta.totals ? n(meta.totals[total.key] ?? 0) : '—' }}</span>
            </div>
        </div>

        <div v-if="!unavailable" class="rounded-xl border border-line bg-surface">
            <!-- Toolbar -->
            <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center">
                <div v-if="searchField" class="relative flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="searchField.label"
                        class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                        data-report-search
                    />
                </div>
                <div v-else class="flex-1" />
                <UiButton v-if="panelFields.length" variant="secondary" @click="showFilters = !showFilters">
                    <SlidersHorizontal class="size-4" />{{ t('common.filters') }}
                    <UiBadge v-if="activeFilterCount" tone="brand">{{ formatNumber(activeFilterCount) }}</UiBadge>
                </UiButton>
                <UiButton v-if="anyFilter" variant="ghost" @click="list.resetFilters()"><RotateCcw class="size-4" />{{ t('common.reset') }}</UiButton>
            </div>
            <div v-if="linkedFields.length" class="flex flex-wrap gap-2 border-b border-line px-4 py-3" data-linked-filters>
                <span v-for="field in linkedFields" :key="field.key" class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-line bg-surface-2 py-1 pr-1 pl-3 text-sm" :data-linked-filter="field.key">
                    <span class="min-w-0 break-words">
                        <span class="text-muted">{{ field.label }}:</span> <span class="font-medium text-fg">{{ cardholderNames[filters[field.key]] ?? t('reports.oneCardholder') }}</span>
                    </span>
                    <button
                        type="button"
                        class="grid size-6 shrink-0 place-items-center rounded-full text-muted hover:bg-surface hover:text-fg"
                        :aria-label="t('reports.removeFilter', { name: field.label })"
                        :title="t('reports.removeFilter', { name: field.label })"
                        @click="filters[field.key] = ''"
                    >
                        <X class="size-3.5" />
                    </button>
                </span>
            </div>
            <div v-if="showFilters" class="grid gap-4 border-b border-line bg-surface-2/50 p-4 sm:grid-cols-2 lg:grid-cols-4" data-report-filters>
                <template v-for="field in panelFields" :key="field.key">
                    <!-- An end date cannot be picked before its start -->
                    <UiInput
                        v-if="field.type === 'date'"
                        v-model="filters[field.key]"
                        type="date"
                        :min="(field.after && filters[field.after]) || undefined"
                        :label="field.label"
                        :error="list.filterError(field.key)"
                        :data-filter="field.key"
                    />
                    <UiInput
                        v-else-if="field.type === 'number'"
                        v-model="filters[field.key]"
                        type="number"
                        :min="(field.after && filters[field.after]) || field.min?.toString()"
                        :max="field.max?.toString()"
                        :placeholder="field.default"
                        :label="field.label"
                        :error="list.filterError(field.key)"
                        :data-filter="field.key"
                    />
                    <UiInput v-else-if="field.type === 'search'" v-model="filters[field.key]" :label="field.label" :error="list.filterError(field.key)" :data-filter="field.key" />
                    <UiSelect
                        v-else
                        v-model="filters[field.key]"
                        :label="field.label"
                        :placeholder="placeholderOf(field)"
                        :options="optionsOf(field)"
                        :error="list.filterError(field.key)"
                        :data-filter="field.key"
                    />
                </template>
            </div>

            <ReportTable v-if="rows.length" :columns="chosen.columns.value" :rows="rows" :loading="loading" :sort="currentSort" :sortable="report?.sorts" :time-zone="timeZone" :first-row="meta.from ?? 1" @toggle-sort="toggleSort">
                <template #cell="{ column, row, text }">
                    <RouterLink
                        v-if="column.key === 'holder.name' && (row as ReportRow).cardholder && auth.can('cardholders.view')"
                        :to="{ path: `/cardholders/${(row as ReportRow).cardholder?.id}`, query: { tab: 'cards' } }"
                        class="font-medium text-fg hover:underline"
                    >
                        {{ text }}
                    </RouterLink>
                    <button
                        v-else-if="column.key === 'card.number' && (row as ReportRow).card && auth.can('cards.view')"
                        type="button"
                        class="font-mono whitespace-nowrap text-fg hover:underline"
                        data-card-number
                        @click="showCard((row as ReportRow).card?.id ?? '')"
                    >
                        {{ text }}
                    </button>
                    <template v-else>{{ text }}</template>
                </template>
            </ReportTable>
            <UiEmpty v-else-if="!loading && report" :title="t('reports.empty')" :hint="anyFilter ? t('common.noResultsHint') : undefined" />
            <ListPagination :list="list" />
        </div>

        <ReportColumnsDialog v-if="report" v-model:open="columnsOpen" :report="report" :chosen="chosen.keys.value" @apply="chosen.choose" @saved="registry.replace" />
        <CardDetailDialog v-model:open="detailOpen" :card-id="detailId" @changed="list.load()" />
    </div>
</template>
