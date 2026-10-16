<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus } from 'lucide-vue-next';
import ListPagination from '@/components/ListPagination.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useListQuery } from '@/composables/useListQuery';
import { toApiError } from '@/lib/http';
import { todayIn } from '@/lib/appointments/availability';
import { closureDates, deleteClosure, fetchYearlyClosures, type AppointmentClosure } from '@/lib/appointments/closures';
import { fetchPlaces, type AppointmentPlace } from '@/lib/appointments/places';
import AppointmentClosureDialog from '@/pages/appointments/AppointmentClosureDialog.vue';
import AppointmentClosureRow from '@/pages/appointments/AppointmentClosureRow.vue';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';

/**
 * Days appointments are not taken, each saved on its own (its own API — apart from the page's save bar): the
 * yearly holidays in the order of the year, and the closed days of given days from the organization's today on
 * (those over too, on request), in pages; of every place or one place. Added, changed and deleted here.
 */
defineProps<{ canEdit: boolean }>();
const { t, locale } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const places = ref<AppointmentPlace[]>([]);
onMounted(async () => {
    try {
        places.value = await fetchPlaces();
    } catch {
        // Without the places, closures still list (each names its place) and every-place ones can be added
    }
});

// Closed days of given days: in pages, their filters in the URL (beside the tab)
const PER_PAGE = 25;
const list = useListQuery<AppointmentClosure>('/appointment-closures', { filters: { location: '', past: '' }, perPage: PER_PAGE, queryPrefix: 'cl_', extraParams: () => ({ 'filter[yearly]': 0 }) });
const past = computed({ get: () => list.filters.past === '1', set: (value: boolean) => (list.filters.past = value ? '1' : '') });

// Yearly holidays: few, in one go, in the order of the year
const yearly = ref<AppointmentClosure[]>([]);
const yearlyFailed = ref(false);
async function loadYearly(): Promise<void> {
    try {
        yearly.value = await fetchYearlyClosures(list.filters.location);
        yearlyFailed.value = false;
    } catch {
        yearlyFailed.value = true;
    }
}
function reload(): void {
    list.load();
    loadYearly();
}
onMounted(reload);
watch(() => list.filters.location, loadYearly);

const dialog = ref(false);
const editing = ref<AppointmentClosure | null>(null);
function edit(closure: AppointmentClosure | null): void {
    editing.value = closure;
    dialog.value = true;
}

/** The organization's today (its own time zone), to mark closures already over. */
const today = computed(() => todayIn(auth.tenant?.timezone));
const days = (closure: AppointmentClosure) => closureDates(closure, locale.value);

async function remove(closure: AppointmentClosure): Promise<void> {
    if (!(await confirm({ title: t('appointments.closures.deleteConfirm', { days: days(closure) }), message: t('appointments.closures.deleteHint'), confirmLabel: t('appointments.closures.delete'), danger: true }))) {
        return;
    }
    try {
        await deleteClosure(closure.id);
        ui.notify(t('appointments.closures.deleted'));
        reload();
    } catch (failure) {
        ui.notify(toApiError(failure).message, 'error');
    }
}
</script>

<template>
    <div class="space-y-6">
        <div v-if="places.length > 1 || canEdit" class="flex flex-wrap items-end justify-between gap-3">
            <div v-if="places.length > 1" class="w-full sm:w-72" data-filter-place>
                <UiSelect
                    v-model="list.filters.location"
                    :label="t('appointments.closures.filterPlace')"
                    :options="[{ value: '', label: t('appointments.closures.filterPlaceAll') }, ...places.map((place) => ({ value: place.id, label: place.name }))]"
                />
            </div>
            <UiButton v-if="canEdit" class="ml-auto" data-add-closure @click="edit(null)"><Plus class="size-4" />{{ t('appointments.closures.add') }}</UiButton>
        </div>

        <UiCard :title="t('appointments.closures.yearlyTitle')" :description="t('appointments.closures.yearlyDescription')" :padded="false" data-yearly-card>
            <UiEmpty v-if="yearlyFailed" :title="t('appointments.closures.loadFailed')" :hint="t('common.error')">
                <UiButton variant="secondary" @click="loadYearly">{{ t('appointments.retry') }}</UiButton>
            </UiEmpty>
            <p v-else-if="!yearly.length" class="px-5 py-6 text-sm text-muted" data-yearly-empty>{{ t('appointments.closures.yearlyEmpty') }}</p>
            <ul v-else class="divide-y divide-line">
                <AppointmentClosureRow v-for="closure in yearly" :key="closure.id" :closure="closure" :can-edit="canEdit" :today="today" @edit="edit(closure)" @remove="remove(closure)" />
            </ul>
        </UiCard>

        <UiCard :title="t('appointments.closures.datedTitle')" :description="t('appointments.closures.datedDescription')" :padded="false" data-dated-card>
            <div class="border-b border-line px-5 py-3">
                <UiCheckbox v-model="past" :label="t('appointments.closures.past')" data-filter-past />
            </div>
            <p v-if="list.loading.value && !list.rows.value.length" class="py-10 text-center text-sm text-muted">{{ t('common.loading') }}</p>
            <UiEmpty v-else-if="list.failed.value" :title="t('appointments.closures.loadFailed')" :hint="t('common.error')">
                <UiButton variant="secondary" @click="list.load">{{ t('appointments.retry') }}</UiButton>
            </UiEmpty>
            <UiEmpty v-else-if="!list.rows.value.length" :title="past ? t('appointments.closures.emptyPast') : t('appointments.closures.empty')" :hint="t('appointments.closures.emptyHint')" />
            <template v-else>
                <ul class="divide-y divide-line" :class="list.loading.value && 'opacity-60'">
                    <AppointmentClosureRow v-for="closure in list.rows.value" :key="closure.id" :closure="closure" :can-edit="canEdit" :today="today" @edit="edit(closure)" @remove="remove(closure)" />
                </ul>
                <!-- Paging only once there is more than a page (or another page size was chosen) -->
                <div v-if="list.meta.value.last_page > 1 || list.perPage.value !== PER_PAGE" class="border-t border-line px-5 py-3">
                    <ListPagination :list="list" />
                </div>
            </template>
        </UiCard>

        <AppointmentClosureDialog v-model:open="dialog" :closure="editing" :places="places" :can-edit="canEdit" @saved="reload" />
    </div>
</template>
