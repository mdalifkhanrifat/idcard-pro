<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { watchDebounced } from '@vueuse/core';
import { X } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { toApiError } from '@/lib/http';
import { fetchAppointmentSettings, type AppointmentSettingsResponse } from '@/lib/appointments/api';
import { bookAppointment, findPeople, type Appointment, type BookablePerson } from '@/lib/appointments/bookings';
import { fetchPlaces, type AppointmentPlace } from '@/lib/appointments/places';
import { serviceName } from '@/lib/appointments/services';
import { formatLongDate, formatNumber, localDigits } from '@/lib/format';
import AvailabilityWeek from '@/pages/appointments/AvailabilityWeek.vue';
import { useUiStore } from '@/stores/ui';

/**
 * Booking an appointment for someone (5.5b), by staff: the person (found by name or employee number — only what
 * booking needs), a family member of theirs where the organization books for them, the kind of visit, the place
 * (by itself when there is one), a free time or day of the week shown, a note. The server checks it all again;
 * its refusals come under their fields. `person` starts it for someone already known (their page).
 */
const props = defineProps<{ person?: BookablePerson | null }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ booked: [appointment: Appointment] }>();
const { t, locale } = useI18n();
const ui = useUiStore();

const setup = ref<AppointmentSettingsResponse | null>(null);
const places = ref<AppointmentPlace[]>([]);
const loadFailed = ref(false);
const person = ref<BookablePerson | null>(null);
const member = ref('');
const service = ref('');
const place = ref('');
const chosen = ref<{ date: string; time: string | null } | null>(null);
const note = ref('');
const errors = ref<Record<string, string[]>>({});
const saving = ref(false);


// The person
const searchState = reactive({ text: '', found: [] as BookablePerson[], searching: false, failed: null as string | null });
watchDebounced(() => searchState.text, async (text) => {
    if (text.trim().length < 2) {
        searchState.found = [];
        return;
    }
    searchState.searching = true;
    try {
        searchState.found = await findPeople(text.trim());
        searchState.failed = null;
    } catch (error) {
        searchState.failed = toApiError(error).message;
    } finally {
        searchState.searching = false;
    }
}, { debounce: 300 });
function choose(found: BookablePerson): void {
    person.value = found;
    member.value = '';
}
const families = computed(() => !!setup.value?.appointments.family_members && !!person.value?.family_members.length);
const members = computed(() => [
    { value: '', label: t('appointments.book.themself') },
    ...(person.value?.family_members ?? []).map((m) => ({ value: m.id, label: `${m.name} · ${t(`cardholders.family.relations.${m.relation}`)}` })),
]);

// Each time it opens: from the start (or the person given), with the settings and places as they are now
watch(open, async (value) => {
    if (!value) {
        return;
    }
    person.value = props.person ?? null;
    Object.assign(searchState, { text: '', found: [], searching: false, failed: null });
    member.value = '';
    chosen.value = null;
    note.value = '';
    errors.value = {};
    loadFailed.value = false;
    try {
        [setup.value, places.value] = await Promise.all([fetchAppointmentSettings(), fetchPlaces()]);
        service.value = services.value[0]?.value ?? '';
    } catch {
        loadFailed.value = true;
    }
}, { immediate: true });

// The kind of visit and the place
const services = computed(() => {
    const settings = setup.value;

    return settings ? Object.entries(settings.appointments.services).filter(([, kind]) => kind.enabled).map(([key, kind]) => ({ value: key, label: serviceName(key, kind, settings.options, locale.value) })) : [];
});
/** On, and taking the kind of visit. */
const placeChoices = computed(() => places.value.filter((p) => p.is_active && (!p.services || p.services.includes(service.value))).map((p) => ({ value: p.id, label: p.name })));
watch(placeChoices, (choices) => {
    if (!choices.some((choice) => choice.value === place.value)) {
        place.value = choices[0]?.value ?? '';
    }
});
watch([service, place], () => (chosen.value = null));

const ready = computed(() => !!person.value && !!service.value && !!place.value && !!chosen.value);
const chosenText = computed(() => {
    if (!chosen.value) {
        return '';
    }
    const date = formatLongDate(chosen.value.date, false);

    return chosen.value.time ? `${date}, ${localDigits(chosen.value.time)}` : `${date} · ${t('appointments.list.inTurn')}`;
});
const fieldError = (...keys: string[]) => keys.map((key) => errors.value[key]?.[0]).find(Boolean);
const week = ref<InstanceType<typeof AvailabilityWeek> | null>(null);

async function book(): Promise<void> {
    if (!ready.value || !person.value || !chosen.value) {
        return;
    }
    saving.value = true;
    errors.value = {};
    try {
        const booked = await bookAppointment({
            location: place.value, service: service.value, cardholder: person.value.id, family_member: member.value || null,
            date: chosen.value.date, time: chosen.value.time, note: note.value.trim() || null,
        });
        ui.notify(t('appointments.book.done', { number: booked.number }), 'success');
        emit('booked', booked);
        open.value = false;
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.errors;
        ui.notify(apiError.message, 'error');
        // The time may have been taken meanwhile: show what is free now
        if (errors.value.time || errors.value.date) {
            chosen.value = null;
            week.value?.reload();
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <UiDialog v-model:open="open" :title="t('appointments.book.title')" :description="t('appointments.book.description')" size="xl" full-on-phone>
        <p v-if="loadFailed" class="py-8 text-center text-sm text-muted" data-book-failed>{{ t('appointments.loadFailed') }}</p>
        <div v-else class="space-y-5" data-book>
            <!-- The person -->
            <section class="space-y-2">
                <h3 class="text-sm font-medium text-fg">{{ t('appointments.book.person') }}</h3>
                <div v-if="person" class="flex items-start justify-between gap-3 rounded-lg border border-line px-3 py-2 text-sm" data-book-person>
                    <span class="min-w-0">
                        <span class="block font-medium break-words text-fg">{{ person.name }}</span>
                        <span class="block text-xs text-muted">{{ person.employee_no }}<template v-if="person.status !== 'active'"> · {{ t(`cardholders.status.${person.status}`) }}</template></span>
                    </span>
                    <button v-if="!props.person" type="button" class="text-muted hover:text-fg" :aria-label="t('appointments.book.change')" data-book-change @click="person = null"><X class="size-4" /></button>
                </div>
                <template v-else>
                    <UiInput v-model="searchState.text" type="search" :placeholder="t('appointments.book.searchHint')" :error="fieldError('cardholder')" autofocus data-book-search />
                    <p v-if="searchState.failed" class="text-xs text-danger">{{ searchState.failed }}</p>
                    <ul v-if="searchState.found.length" class="divide-y divide-line rounded-lg border border-line" data-book-found>
                        <li v-for="found in searchState.found" :key="found.id">
                            <button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-surface-2" :data-book-found-id="found.id" @click="choose(found)">
                                <span class="block break-words text-fg">{{ found.name }}<span v-if="found.name_local" class="text-muted"> · {{ found.name_local }}</span></span>
                                <span class="block text-xs text-muted">{{ found.employee_no }}<template v-if="found.family_members.length"> · {{ t('appointments.book.family', { count: formatNumber(found.family_members.length) }, found.family_members.length) }}</template></span>
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="searchState.text.trim().length >= 2 && !searchState.searching" class="text-xs text-muted" data-book-nobody>{{ t('appointments.book.nobody') }}</p>
                </template>
                <UiSelect v-if="families" v-model="member" :label="t('appointments.book.forWhom')" :options="members" :error="fieldError('family_member')" data-book-member />
            </section>

            <!-- What and where -->
            <section class="grid gap-4 sm:grid-cols-2">
                <UiSelect v-model="service" :label="t('appointments.list.service')" :options="services" :error="fieldError('service')" data-book-service />
                <UiSelect v-if="placeChoices.length > 1" v-model="place" :label="t('appointments.list.place')" :options="placeChoices" :error="fieldError('location')" data-book-place />
                <p v-else-if="!placeChoices.length && setup" class="text-sm text-muted sm:self-end" data-book-no-place>{{ t('appointments.book.noPlace') }}</p>
            </section>

            <!-- When -->
            <section v-if="place && service" class="space-y-2">
                <h3 class="text-sm font-medium text-fg">{{ t('appointments.book.when') }}</h3>
                <AvailabilityWeek ref="week" v-model:chosen="chosen" :place-id="place" :service="service" :days-ahead="setup?.appointments.days_ahead ?? 30" selectable />
                <p v-if="fieldError('date', 'time')" class="text-sm text-danger" data-book-when-error>{{ fieldError('date', 'time') }}</p>
            </section>

            <UiTextarea v-model="note" :label="t('appointments.book.note')" :rows="2" :maxlength="500" :error="fieldError('note')" data-book-note />
        </div>
        <template #footer>
            <div class="flex w-full flex-wrap items-center justify-end gap-2">
                <p v-if="chosen" class="mr-auto text-sm text-fg max-sm:basis-full" data-book-chosen>{{ chosenText }}</p>
                <UiButton variant="secondary" class="whitespace-nowrap" @click="open = false">{{ t('common.cancel') }}</UiButton>
                <UiButton :disabled="!ready" :loading="saving" class="whitespace-nowrap" data-book-submit @click="book">{{ t('appointments.book.submit') }}</UiButton>
            </div>
        </template>
    </UiDialog>
</template>
