<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { CalendarPlus, RefreshCw } from 'lucide-vue-next';
import ListPagination from '@/components/ListPagination.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useListQuery, type PageMeta } from '@/composables/useListQuery';
import { useUrlOpen } from '@/composables/useUrlOpen';
import type { Appointment, AppointmentListOptions, BookablePerson } from '@/lib/appointments/bookings';
import { http } from '@/lib/http';
import { formatNumber } from '@/lib/format';
import AppointmentBookDialog from '@/pages/appointments/AppointmentBookDialog.vue';
import AppointmentDetailDialog from '@/pages/appointments/AppointmentDetailDialog.vue';
import AppointmentRow from '@/pages/appointments/AppointmentRow.vue';
import ReportDownloads from '@/pages/reports/ReportDownloads.vue';
import { useAuthStore } from '@/stores/auth';
import type { Cardholder, FamilyMember } from '@/types';

/**
 * A cardholder's appointments (5.5d) — their own and their family members', every day, the latest first — with
 * booking for them (the person already chosen) and each one's details (?ap_open=). Its list keeps its criteria
 * under ap_* in the URL. Shown while appointments are off too, for the bookings made before.
 */
const props = defineProps<{ cardholder: Cardholder }>();
const emit = defineEmits<{ booked: [appointment: Appointment] }>();
const { t } = useI18n();
const auth = useAuthStore();

const list = useListQuery<Appointment, PageMeta & Partial<AppointmentListOptions>>('/appointments', {
    filters: { status: '', service: '' },
    sort: '-date',
    perPage: 20,
    queryPrefix: 'ap_',
    extraParams: () => fixed(),
});
onMounted(() => list.load());
/** The tab is the "Appointments" report for this person: every day, theirs and their family's. */
const fixed = () => ({ 'filter[cardholder]': props.cardholder.id, 'filter[past]': '1' });
const downloadParams = () => ({ ...list.queryParams(), ...fixed() });
/** The same criteria on the report's page, under the report's names (the tab keeps its own under ap_*). */
const reportQuery = computed(() => Object.fromEntries([
    ...Object.entries(list.filters).filter(([, value]) => value !== '').map(([key, value]) => [`f_${key}`, value]),
    ['f_cardholder', props.cardholder.id],
    ['f_past', '1'],
    ['sort', list.sort.value],
]));
const choices = (named: { key: string; name: string }[] | undefined) => (named ?? []).map((item) => ({ value: item.key, label: item.name }));
const filtered = computed(() => list.filters.status !== '' || list.filters.service !== '');

const opened = useUrlOpen('ap_open');
const detailOpen = computed({
    get: () => opened.value !== null,
    set: (value) => {
        if (!value) {
            opened.value = null;
        }
    },
});

const off = computed(() => !auth.tenant?.appointments?.enabled);
/** Staff book while the organization books through staff (and appointments are on). */
const canBook = computed(() => !off.value && auth.can('appointments.book') && (auth.tenant?.appointments?.booking ?? []).includes('staff'));
const booking = ref(false);
/** The person to book for: this cardholder, with their family members as they are now. */
const person = ref<BookablePerson | null>(null);
async function book(): Promise<void> {
    const c = props.cardholder;
    const members: FamilyMember[] = (await http.get(`/cardholders/${c.id}/family-members`)).data.data;
    person.value = { id: c.id, name: c.full_name, name_local: c.full_name_local, employee_no: c.employee_no, status: c.status, family_members: members.map((m) => ({ id: m.id, name: m.name, relation: m.relation })) };
    booking.value = true;
}
function booked(appointment: Appointment): void {
    list.load();
    emit('booked', appointment);
}
</script>

<template>
    <div class="rounded-xl border border-line bg-surface" data-tab="appointments">
        <div class="flex flex-wrap items-end gap-3 border-b border-line p-4">
            <UiSelect v-model="list.filters.status" :label="t('appointments.list.status')" :placeholder="t('common.all')" :options="choices(list.meta.value.statuses)" class="w-full sm:w-48" data-filter="status" />
            <UiSelect v-model="list.filters.service" :label="t('appointments.list.service')" :placeholder="t('common.all')" :options="choices(list.meta.value.services)" class="w-full sm:w-48" data-filter="service" />
            <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                <UiButton variant="secondary" size="sm" :loading="list.loading.value" @click="list.load()"><RefreshCw class="size-4" />{{ t('appointments.list.refresh') }}</UiButton>
                <ReportDownloads v-if="auth.can('reports.view')" report-key="appointments" variant="menu" :params="downloadParams" :total="list.meta.value.total" :link-query="reportQuery" />
                <UiButton v-if="canBook" size="sm" data-book-open @click="book"><CalendarPlus class="size-4" />{{ t('appointments.book.open') }}</UiButton>
            </div>
        </div>
        <p v-if="off" class="border-b border-line bg-warning/10 px-4 py-3 text-sm text-fg" data-off-note>{{ t('appointments.list.off') }}</p>
        <p v-if="list.meta.value.total" class="px-5 pt-3 text-xs text-muted" data-total>{{ t('cardholders.appointments.total', { count: formatNumber(list.meta.value.total) }, list.meta.value.total) }}</p>

        <ul class="divide-y divide-line" :class="list.loading.value && 'opacity-50'">
            <li v-for="row in list.rows.value" :key="row.id" :data-row="row.number">
                <AppointmentRow :row="row" show-date :show-holder="false" :active="opened === row.id" @open="opened = row.id" />
            </li>
        </ul>
        <div v-if="list.failed.value && !list.loading.value" class="flex flex-col items-center gap-3 px-5 py-10 text-sm text-muted" data-failed>
            {{ t('appointments.list.failed') }}<UiButton variant="secondary" size="sm" @click="list.load()">{{ t('appointments.retry') }}</UiButton>
        </div>
        <UiEmpty
            v-else-if="!list.loading.value && !list.rows.value.length"
            :title="filtered ? t('common.noResults') : t('cardholders.appointments.none')"
            :hint="filtered ? t('common.noResultsHint') : t('cardholders.appointments.noneHint')"
            data-empty
        />
        <ListPagination :list="list" />

        <AppointmentBookDialog v-if="canBook" v-model:open="booking" :person="person" @booked="booked" />
        <AppointmentDetailDialog v-model:open="detailOpen" :appointment-id="opened" @changed="list.load()" />
    </div>
</template>
