<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import ListPagination from '@/components/ListPagination.vue';
import ReportDownloads from '@/pages/reports/ReportDownloads.vue';
import { FileUp, Pencil, Plus, RotateCcw, Search, SlidersHorizontal, Trash2, UserRound } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiSortHeader from '@/components/ui/UiSortHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useListQuery } from '@/composables/useListQuery';
import { useMasterDataOptions } from '@/composables/useMasterDataOptions';
import { useCustomFields } from '@/composables/useCustomFields';
import { http, toApiError } from '@/lib/http';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { keptAsRecord, statusTone } from './cardholder';
import { useCardsPrinted } from '@/pages/cards/cards';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { Cardholder } from '@/types';

const { t } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();
const lookups = useMasterDataOptions();
const customFields = useCustomFields();
// Drop-down and yes/no custom fields can be filtered on exactly.
const customFilters = computed(() => customFields.fields.value.filter((field) => field.type === 'select' || field.type === 'checkbox'));

const list = useListQuery<Cardholder>('/cardholders', {
    filters: {
        search: '', status: '', employment_type: '', department: '', unit: '', rank: '', designation: '', branch: '', blood_group: '', joined_from: '', joined_to: '', card: '', biometrics: '',
    },
    sort: '-created_at',
});
const { filters, rows, meta, loading, sort, page } = list;

const showFilters = ref(false);
const activeFilterCount = computed(() => Object.entries(filters).filter(([key, v]) => key !== 'search' && v !== '').length);

/** Biometric status filter and badges: only for those who may see biometrics, while the organization captures them. */
const showBiometrics = computed(() => auth.can('biometrics.view') && !!auth.tenant?.biometrics?.enabled);
const biometricTone = (status: string) => ({ complete: 'success', exceptions: 'brand', incomplete: 'warning' })[status] as 'success' | 'brand' | 'warning' | undefined;

const statusOptions = computed(() => (['active', 'inactive', 'retired'] as const).map((value) => ({ value, label: t(`cardholders.status.${value}`) })));
const employmentOptions = computed(() =>
    (['staff', 'contractor', 'visitor', 'student', 'other'] as const).map((value) => ({ value, label: t(`cardholders.employmentType.${value}`) })),
);

// A unit only makes sense inside the chosen department.
watch(() => filters.department, (department, previous) => {
    if (previous !== undefined && department !== previous) {
        filters.unit = '';
    }
});

onMounted(async () => {
    lookups.load(['department', 'unit', 'rank', 'designation', 'branch', 'blood_group']);
    await customFields.load();
    list.addFilters(customFilters.value.map((field) => `custom.${field.key}`));
    list.load();
});

// A card printed in the print page's tab moves its holder from "not printed" to "printed"
useCardsPrinted((cardIds) => {
    if (rows.value.some((row) => row.current_card && cardIds.includes(row.current_card.id))) {
        list.load();
    }
});

async function remove(cardholder: Cardholder): Promise<void> {
    // Kept as a record: say why (a phone has no hover)
    const kept = keptAsRecord(cardholder, t);
    if (kept) {
        ui.notify(kept, 'info');
        return;
    }
    if (!(await confirm({ title: t('common.areYouSure'), message: t('cardholders.deleteConfirm', { name: cardholder.full_name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/cardholders/${cardholder.id}`);
        ui.notify(t('common.deleted'));
        list.load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('cardholders.title')" :subtitle="t('cardholders.subtitle')">
            <!-- The list as found now, through the report of the same name (legacy list Print / PDF / Excel) -->
            <ReportDownloads v-if="auth.can('reports.view')" report-key="cardholders" variant="menu" :params="list.queryParams" :total="meta.total" />
            <UiButton v-if="auth.can('cardholders.import')" variant="secondary" @click="router.push('/cardholders/import')"><FileUp class="size-4" />{{ t('cardholders.import.action') }}</UiButton>
            <UiButton v-if="auth.can('cardholders.create')" @click="router.push('/cardholders/new')"><Plus class="size-4" />{{ t('cardholders.new') }}</UiButton>
        </UiPageHeader>

        <div class="rounded-xl border border-line bg-surface">
            <!-- Toolbar -->
            <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t(auth.can('cards.view') ? 'cardholders.searchPlaceholderCards' : 'cardholders.searchPlaceholder')"
                        class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                    />
                </div>
                <UiButton variant="secondary" @click="showFilters = !showFilters">
                    <SlidersHorizontal class="size-4" />{{ t('common.filters') }}
                    <UiBadge v-if="activeFilterCount" tone="brand">{{ formatNumber(activeFilterCount) }}</UiBadge>
                </UiButton>
                <UiButton v-if="activeFilterCount || filters.search" variant="ghost" @click="list.resetFilters()"><RotateCcw class="size-4" />{{ t('common.reset') }}</UiButton>
            </div>
            <div v-if="showFilters" class="grid gap-4 border-b border-line bg-surface-2/50 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <UiSelect v-model="filters.status" :label="t('cardholders.fields.status')" :placeholder="t('common.all')" :options="statusOptions" />
                <UiSelect v-model="filters.employment_type" :label="t('cardholders.fields.employment_type')" :placeholder="t('common.all')" :options="employmentOptions" />
                <UiSelect v-model="filters.department" :label="t('cardholders.fields.department_id')" :placeholder="t('common.all')" :options="lookups.options('department')" />
                <UiSelect
                    v-model="filters.unit"
                    :label="t('cardholders.fields.unit_id')"
                    :placeholder="filters.department ? t('common.all') : t('cardholders.selectDepartmentFirst')"
                    :options="filters.department ? lookups.options('unit', filters.department) : []"
                />
                <UiSelect v-model="filters.rank" :label="t('cardholders.fields.rank_id')" :placeholder="t('common.all')" :options="lookups.options('rank')" />
                <UiSelect v-model="filters.designation" :label="t('cardholders.fields.designation_id')" :placeholder="t('common.all')" :options="lookups.options('designation')" />
                <UiSelect v-model="filters.branch" :label="t('cardholders.fields.branch_id')" :placeholder="t('common.all')" :options="lookups.options('branch')" />
                <UiSelect v-model="filters.blood_group" :label="t('cardholders.fields.blood_group_id')" :placeholder="t('common.all')" :options="lookups.options('blood_group')" />
                <UiInput v-model="filters.joined_from" type="date" :label="`${t('cardholders.joined')} · ${t('common.from')}`" />
                <UiInput v-model="filters.joined_to" type="date" :min="filters.joined_from || undefined" :error="list.filterError('joined_to')" :label="`${t('cardholders.joined')} · ${t('common.to')}`" />
                <UiSelect
                    v-if="auth.can('cards.view')"
                    v-model="filters.card"
                    :label="t('cardholders.card.label')"
                    :placeholder="t('common.all')"
                    :options="(['none', 'pending', 'printed'] as const).map((value) => ({ value, label: t(`cardholders.card.${value}`) }))"
                    data-filter-card
                />
                <UiSelect
                    v-if="showBiometrics"
                    v-model="filters.biometrics"
                    :label="t('biometrics.statusLabel')"
                    :placeholder="t('common.all')"
                    :options="(['incomplete', 'complete', 'exceptions', 'not_required'] as const).map((value) => ({ value, label: t(`biometrics.statuses.${value}`) }))"
                    data-filter-biometrics
                />
                <UiSelect
                    v-for="field in customFilters"
                    :key="field.key"
                    v-model="filters[`custom.${field.key}`]"
                    :label="customFields.label(field)"
                    :placeholder="t('common.all')"
                    :options="field.type === 'checkbox'
                        ? [{ value: 'true', label: t('common.yes') }, { value: 'false', label: t('common.no') }]
                        : (field.options ?? []).map((option) => ({ value: option, label: option }))"
                />
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs font-medium tracking-wide whitespace-nowrap text-muted uppercase">
                        <tr class="border-b border-line">
                            <th class="px-5 py-3"><UiSortHeader field="full_name" :sort="sort" :label="t('cardholders.fields.full_name')" @toggle="list.toggleSort" /></th>
                            <th class="px-5 py-3"><UiSortHeader field="employee_no" :sort="sort" :label="t('cardholders.employeeNo')" @toggle="list.toggleSort" /></th>
                            <th class="px-5 py-3">{{ t('cardholders.fields.department_id') }}</th>
                            <th class="px-5 py-3">{{ t('cardholders.fields.designation_id') }}</th>
                            <th class="px-5 py-3"><UiSortHeader field="joining_date" :sort="sort" :label="t('cardholders.joined')" @toggle="list.toggleSort" /></th>
                            <th class="px-5 py-3">{{ t('common.status') }}</th>
                            <th class="px-5 py-3 text-right">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody :class="loading && 'opacity-50'">
                        <tr v-for="row in rows" :key="row.id" class="border-b border-line last:border-0 hover:bg-surface-2/60">
                            <td class="px-5 py-3">
                                <RouterLink :to="`/cardholders/${row.id}`" class="flex items-center gap-3">
                                    <img v-if="row.photo" :src="row.photo.url" alt="" loading="lazy" class="size-10 shrink-0 rounded-lg border border-line object-cover" />
                                    <span v-else class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-surface-2 text-muted" :title="t('cardholders.noPhoto')">
                                        <UserRound class="size-5" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-fg hover:text-brand-ink">{{ row.full_name }}</span>
                                        <span class="block truncate text-xs text-muted">
                                            {{ row.full_name_local }}<template v-if="row.full_name_local && row.personal_phone"> · </template>{{ row.personal_phone }}
                                        </span>
                                    </span>
                                </RouterLink>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs whitespace-nowrap text-muted">{{ row.employee_no ?? '—' }}</td>
                            <td class="px-5 py-3 text-muted">
                                {{ row.department?.name ?? '—' }}
                                <span v-if="row.unit" class="block text-xs">{{ row.unit.name }}</span>
                            </td>
                            <td class="px-5 py-3 text-muted">
                                {{ row.designation?.name ?? '—' }}
                                <span v-if="row.rank" class="block text-xs">{{ row.rank.name }}</span>
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap text-muted">{{ formatDate(row.joining_date) }}</td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <UiBadge :tone="statusTone(row.status)">{{ t(`cardholders.status.${row.status}`) }}</UiBadge>
                                    <UiBadge v-if="row.employment_type !== 'staff'">{{ t(`cardholders.employmentType.${row.employment_type}`) }}</UiBadge>
                                    <UiBadge v-if="showBiometrics && row.biometric_status && row.biometric_status !== 'not_required'" :tone="biometricTone(row.biometric_status)" :data-biometric-status="row.biometric_status">
                                        {{ t('biometrics.statusBadge', { status: t(`biometrics.statuses.${row.biometric_status}`) }) }}
                                    </UiBadge>
                                </div>
                                <!-- Own card, legacy "Status": PENDING (never printed) / PRINTED -->
                                <div v-if="auth.can('cards.view')" class="mt-1.5 text-xs" data-card-state>
                                    <span
                                        v-if="row.current_card"
                                        :class="row.current_card.print_count ? 'text-success' : 'text-warning'"
                                        :title="row.current_card.last_printed_at ? formatDateTime(row.current_card.last_printed_at) : undefined"
                                    >
                                        {{ t(row.current_card.print_count ? 'cardholders.card.printed' : 'cardholders.card.pending') }}
                                        <span class="block font-mono text-muted">{{ row.current_card.number }}</span>
                                    </span>
                                    <span v-else class="text-muted">{{ t('cardholders.card.none') }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-1">
                                    <RouterLink
                                        v-if="auth.can('cardholders.update')"
                                        :to="`/cardholders/${row.id}/edit`"
                                        class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg"
                                        :aria-label="t('common.edit')"
                                    >
                                        <Pencil class="size-4" />
                                    </RouterLink>
                                    <button
                                        v-if="auth.can('cardholders.delete')"
                                        class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-muted"
                                        :class="keptAsRecord(row, t) && 'opacity-40 hover:bg-transparent hover:text-muted'"
                                        :aria-disabled="!!keptAsRecord(row, t)"
                                        :aria-label="t('common.delete')"
                                        :title="keptAsRecord(row, t) ?? t('common.delete')"
                                        data-delete-cardholder
                                        @click="remove(row)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <UiEmpty v-if="!loading && !rows.length" :title="t('common.noResults')" :hint="t('common.noResultsHint')" />
            </div>
            <ListPagination :list="list" />
        </div>
    </div>
</template>
