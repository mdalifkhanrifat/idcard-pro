<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { CalendarCog, CalendarPlus, ChevronLeft, ChevronRight, RefreshCw, SlidersHorizontal } from 'lucide-vue-next';
import ListPagination from '@/components/ListPagination.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useListQuery, type PageMeta } from '@/composables/useListQuery';
import { useUrlOpen } from '@/composables/useUrlOpen';
import { addDays, todayIn } from '@/lib/appointments/availability';
import { filtersFor, namesDays, scopeOf, type Appointment, type AppointmentListOptions, type AppointmentScope } from '@/lib/appointments/bookings';
import { fetchPlaces, type AppointmentPlace } from '@/lib/appointments/places';
import { formatLongDate, formatNumber } from '@/lib/format';
import AppointmentBookDialog from '@/pages/appointments/AppointmentBookDialog.vue';
import AppointmentCheckIn from '@/pages/appointments/AppointmentCheckIn.vue';
import AppointmentDetailDialog from '@/pages/appointments/AppointmentDetailDialog.vue';
import AppointmentRow from '@/pages/appointments/AppointmentRow.vue';
import ReportDownloads from '@/pages/reports/ReportDownloads.vue';
import { useAuthStore } from '@/stores/auth';

/**
 * The staff's appointments (5.5): a day's bookings in time order by default — or those from today on, or every
 * day —, by place, status, kind of visit and search. Every name (statuses, kinds) comes from the server. Seen
 * while appointments are off too (their history), with a note.
 */
const { t } = useI18n();
const auth = useAuthStore();
const timeZone = computed(() => auth.tenant?.timezone);
const today = todayIn(timeZone.value);
// Opened afresh: today; a link (or a reload) keeps the days it names
const days = namesDays(useRoute().query) ? { from: '', to: '', past: '' } : filtersFor('day', today, today);

const list = useListQuery<Appointment, PageMeta & Partial<AppointmentListOptions>>('/appointments', {
    filters: { ...days, location: '', status: '', service: '', search: '' },
    sort: 'date',
    perPage: 50,
});
const places = ref<AppointmentPlace[]>([]);
onMounted(async () => {
    list.load();
    places.value = await fetchPlaces().catch(() => []);
});

const scope = computed<AppointmentScope>({
    get: () => scopeOf(list.filters as { from: string; to: string; past: string }),
    set: (value) => {
        Object.assign(list.filters, filtersFor(value, list.filters.from || today, today));
        // Every day: the latest first
        list.sort.value = value === 'all' ? '-date' : 'date';
    },
});
const scopes = computed(() => (['day', 'upcoming', 'all'] as const).map((value) => ({ value, label: t(`appointments.list.scopes.${value}`) })));
const day = computed(() => list.filters.from);
function goTo(next: string): void {
    if (next) {
        Object.assign(list.filters, filtersFor('day', next, today));
    }
}
const dayLabel = computed(() => formatLongDate(day.value));

const choices = (named: { key: string; name: string }[] | undefined) => (named ?? []).map((item) => ({ value: item.key, label: item.name }));
const placeChoices = computed(() => places.value.map((place) => ({ value: place.id, label: place.name })));
/** With one place, it goes without saying. */
const manyPlaces = computed(() => places.value.length > 1);
const sorts = computed(() => [
    { value: 'date', label: t('appointments.list.sorts.date') },
    { value: '-date', label: t('appointments.list.sorts.latest') },
    { value: 'number', label: t('appointments.list.sorts.number') },
    { value: '-created_at', label: t('appointments.list.sorts.booked') },
]);
const otherFilters = ['location', 'status', 'service', 'search'] as const;
const filtered = computed(() => otherFilters.some((key) => list.filters[key] !== ''));
function resetOthers(): void {
    for (const key of otherFilters) {
        list.filters[key] = '';
    }
}
/** On a phone the filters other than the search open on demand, so the bookings come first. */
const moreFilters = ref(false);
const activeFilters = computed(() => (['location', 'status', 'service'] as const).filter((key) => list.filters[key] !== '').length);

const off = computed(() => !auth.tenant?.appointments?.enabled);
/** Staff book while the organization books through staff (and appointments are on). */
const canBook = computed(() => !off.value && auth.can('appointments.book') && (auth.tenant?.appointments?.booking ?? []).includes('staff'));
const booking = ref(false);
/** A new booking: its day, so it shows in the list. */
function booked(appointment: Appointment): void {
    if (scope.value === 'day' && day.value !== appointment.date) {
        goTo(appointment.date);
    } else {
        list.load();
    }
}
/** The appointment open in its details, kept in the link (?open=). */
const opened = useUrlOpen();
const detailOpen = computed({
    get: () => opened.value !== null,
    set: (value) => {
        if (!value) {
            opened.value = null;
            // Back to the number box for the next person
            if (checkingIn.value) {
                checkingIn.value = false;
                checkIn.value?.focus();
            }
        }
    },
});
/** Opened by its number at the desk: its first step takes the focus. */
const checkingIn = ref(false);
const checkIn = ref<InstanceType<typeof AppointmentCheckIn> | null>(null);
function found(appointment: Appointment): void {
    checkingIn.value = true;
    opened.value = appointment.id;
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('appointments.list.title')" :subtitle="t('appointments.list.subtitle')">
            <UiButton v-if="canBook" data-book-open @click="booking = true"><CalendarPlus class="size-4" />{{ t('appointments.book.open') }}</UiButton>
            <RouterLink v-if="auth.can(['appointments.view', 'settings.update'])" to="/appointments/settings" class="inline-flex items-center gap-1.5 text-sm text-brand-ink hover:underline" data-settings-link>
                <CalendarCog class="size-4" />{{ t('nav.appointmentSettings') }}
            </RouterLink>
        </UiPageHeader>

        <p v-if="off" class="mb-4 rounded-lg border border-warning/30 bg-warning/10 px-4 py-3 text-sm text-fg" data-off-note>{{ t('appointments.list.off') }}</p>

        <AppointmentCheckIn ref="checkIn" @found="found" />

        <div class="rounded-xl border border-line bg-surface" data-appointment-list>
            <div class="space-y-3 border-b border-line p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <div role="radiogroup" :aria-label="t('appointments.list.scope')" class="inline-flex rounded-lg border border-line p-0.5" data-scope>
                        <button
                            v-for="option in scopes"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="scope === option.value"
                            class="rounded-md px-3 py-1.5 text-sm"
                            :class="scope === option.value ? 'bg-brand/12 font-medium text-brand-ink' : 'text-muted hover:text-fg'"
                            :data-scope-option="option.value"
                            @click="scope = option.value"
                        >{{ option.label }}</button>
                    </div>
                    <div v-if="scope === 'day'" class="flex flex-wrap items-center gap-2" data-day-nav>
                        <UiButton variant="secondary" size="sm" :aria-label="t('appointments.list.previousDay')" data-previous-day @click="goTo(addDays(day, -1))"><ChevronLeft class="size-4" /></UiButton>
                        <input :value="day" type="date" class="rounded-lg border border-line bg-surface px-2.5 py-1.5 text-sm text-fg" :aria-label="t('appointments.list.day')" data-day @change="goTo(($event.target as HTMLInputElement).value)" />
                        <UiButton variant="secondary" size="sm" :aria-label="t('appointments.list.nextDay')" data-next-day @click="goTo(addDays(day, 1))"><ChevronRight class="size-4" /></UiButton>
                        <UiButton v-if="day !== today" variant="ghost" size="sm" data-today @click="goTo(today)">{{ t('appointments.list.today') }}</UiButton>
                    </div>
                </div>
                <p v-if="scope === 'day'" class="text-sm font-medium text-fg" data-day-label>
                    {{ dayLabel }}<span v-if="day === today" class="ml-2 text-xs font-normal text-muted">{{ t('appointments.list.today') }}</span>
                </p>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <UiInput v-model="list.filters.search" type="search" :label="t('common.search')" :placeholder="t('appointments.list.searchHint')" class="sm:col-span-2" data-filter="search" />
                    <button type="button" class="inline-flex items-center gap-2 justify-self-start text-sm text-brand-ink sm:hidden" :aria-expanded="moreFilters" data-more-filters @click="moreFilters = !moreFilters">
                        <SlidersHorizontal class="size-4" />{{ t('common.filters') }}<template v-if="activeFilters"> ({{ formatNumber(activeFilters) }})</template>
                    </button>
                    <div class="contents" :class="!moreFilters && 'max-sm:hidden'">
                        <UiSelect v-if="manyPlaces" v-model="list.filters.location" :label="t('appointments.list.place')" :placeholder="t('common.all')" :options="placeChoices" data-filter="location" />
                        <UiSelect v-model="list.filters.status" :label="t('appointments.list.status')" :placeholder="t('common.all')" :options="choices(list.meta.value.statuses)" data-filter="status" />
                        <UiSelect v-model="list.filters.service" :label="t('appointments.list.service')" :placeholder="t('common.all')" :options="choices(list.meta.value.services)" data-filter="service" />
                        <UiSelect v-if="scope !== 'day'" v-model="list.sort.value" :label="t('appointments.list.sort')" :options="sorts" data-filter="sort" />
                    </div>
                    <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4 lg:justify-end">
                        <UiButton v-if="filtered" variant="ghost" size="sm" @click="resetOthers()">{{ t('common.reset') }}</UiButton>
                        <UiButton variant="secondary" size="sm" :loading="list.loading.value" data-refresh @click="list.load()"><RefreshCw class="size-4" />{{ t('appointments.list.refresh') }}</UiButton>
                        <!-- The list is the "Appointments" report: its downloads hold these rows (the days shown too) -->
                        <ReportDownloads v-if="auth.can('reports.view')" report-key="appointments" variant="menu" :params="list.queryParams" :total="list.meta.value.total" />
                    </div>
                </div>
            </div>

            <ul class="divide-y divide-line" :class="list.loading.value && 'opacity-50'">
                <li v-for="row in list.rows.value" :key="row.id" :data-row="row.number">
                    <AppointmentRow :row="row" :show-date="scope !== 'day'" :show-place="manyPlaces" :active="opened === row.id" @open="opened = row.id" />
                </li>
            </ul>
            <div v-if="list.failed.value && !list.loading.value" class="flex flex-col items-center gap-3 px-5 py-10 text-sm text-muted" data-failed>
                {{ t('appointments.list.failed') }}<UiButton variant="secondary" size="sm" @click="list.load()">{{ t('appointments.retry') }}</UiButton>
            </div>
            <UiEmpty
                v-else-if="!list.loading.value && !list.rows.value.length"
                :title="filtered ? t('common.noResults') : t(`appointments.list.none.${scope}`)"
                :hint="filtered ? t('common.noResultsHint') : t('appointments.list.noneHint')"
                data-empty
            />
            <ListPagination :list="list" />
        </div>

        <AppointmentBookDialog v-if="canBook" v-model:open="booking" @booked="booked" />
        <AppointmentDetailDialog v-model:open="detailOpen" :appointment-id="opened" :focus-step="checkingIn" @changed="list.load()" />
    </div>
</template>
