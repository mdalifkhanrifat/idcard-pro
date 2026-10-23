<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { fetchAppointmentSettings, type AppointmentSettingsResponse } from '@/lib/appointments/api';
import { wallClock } from '@/lib/appointments/availability';
import { moveAppointment, timeRange, type Appointment } from '@/lib/appointments/bookings';
import { fetchPlaces, type AppointmentPlace } from '@/lib/appointments/places';
import { formatLongDate, localDigits } from '@/lib/format';
import { toApiError } from '@/lib/http';
import AvailabilityWeek from '@/pages/appointments/AvailabilityWeek.vue';
import { useUiStore } from '@/stores/ui';

/**
 * Moving an appointment still ahead (5.5c) to another place, day or time: the places that are on and take its kind
 * of visit (by itself when there is one), and a week's free times with its own place shown free (`except`). Its
 * number, status, kind of visit and person stay; the server checks it all again, by the rules of a new booking,
 * and its refusals come under their fields — a time taken meanwhile shows the week afresh.
 */
const props = defineProps<{ appointment: Appointment | null }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ moved: [appointment: Appointment] }>();
const { t } = useI18n();
const ui = useUiStore();

const setup = ref<AppointmentSettingsResponse | null>(null);
const places = ref<AppointmentPlace[]>([]);
const loadFailed = ref(false);
const place = ref('');
const chosen = ref<{ date: string; time: string | null } | null>(null);
const errors = ref<Record<string, string[]>>({});
const refusal = ref<string | null>(null);
const saving = ref(false);
const week = ref<InstanceType<typeof AvailabilityWeek> | null>(null);

// Each time it opens: its own place first, with the settings and places as they are now
watch(open, async (value) => {
    if (!value || !props.appointment) {
        return;
    }
    chosen.value = null;
    errors.value = {};
    refusal.value = null;
    loadFailed.value = false;
    try {
        [setup.value, places.value] = await Promise.all([fetchAppointmentSettings(), fetchPlaces()]);
        place.value = placeChoices.value.some((choice) => choice.value === props.appointment?.location?.id) ? (props.appointment.location?.id ?? '') : (placeChoices.value[0]?.value ?? '');
    } catch {
        loadFailed.value = true;
    }
}, { immediate: true });

const service = computed(() => props.appointment?.service.key ?? '');
/** On, and taking its kind of visit. */
const placeChoices = computed(() => places.value.filter((p) => p.is_active && (!p.services || p.services.includes(service.value))).map((p) => ({ value: p.id, label: p.name })));
watch(place, () => (chosen.value = null));

const now = computed(() => {
    const shown = props.appointment;
    if (!shown) {
        return '';
    }
    const range = timeRange(shown);

    return [formatLongDate(shown.date), range ? localDigits(range) : t('appointments.list.inTurn'), shown.location?.name].filter(Boolean).join(' · ');
});
/** Its day and time now, marked in the week of its own place. */
const current = computed(() => {
    const shown = props.appointment;

    return shown && place.value === shown.location?.id ? { date: shown.date, time: shown.starts_at ? wallClock(shown.starts_at) : null } : null;
});
/** The time it has now, chosen again: nothing to move. */
const same = computed(() => {
    const shown = props.appointment;

    return !!shown && !!chosen.value && place.value === shown.location?.id && chosen.value.date === shown.date && chosen.value.time === (shown.starts_at ? wallClock(shown.starts_at) : null);
});
const chosenText = computed(() => {
    if (!chosen.value) {
        return '';
    }
    const date = formatLongDate(chosen.value.date, false);

    return chosen.value.time ? `${date}, ${localDigits(chosen.value.time)}` : `${date} · ${t('appointments.list.inTurn')}`;
});
const fieldError = (...keys: string[]) => keys.map((key) => errors.value[key]?.[0]).find(Boolean);

async function move(): Promise<void> {
    const shown = props.appointment;
    if (!shown || !chosen.value || !place.value || same.value) {
        return;
    }
    saving.value = true;
    errors.value = {};
    refusal.value = null;
    try {
        const moved = await moveAppointment(shown.id, { location: place.value, date: chosen.value.date, time: chosen.value.time });
        ui.notify(t('appointments.move.done', { number: moved.number }), 'success');
        emit('moved', moved);
        open.value = false;
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.errors;
        refusal.value = fieldError('location', 'date', 'time') ? null : (apiError.errors.status?.[0] ?? apiError.message);
        ui.notify(fieldError('location', 'date', 'time') ?? refusal.value ?? apiError.message, 'error');
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
    <UiDialog v-model:open="open" :title="t('appointments.move.title', { number: appointment?.number ?? '' })" :description="t('appointments.move.description')" size="xl" full-on-phone>
        <p v-if="loadFailed" class="py-8 text-center text-sm text-muted" data-move-failed>{{ t('appointments.loadFailed') }}</p>
        <div v-else-if="appointment" class="space-y-5" data-move>
            <p class="rounded-lg border border-line px-3 py-2 text-sm break-words text-fg" data-move-now>
                <span class="text-muted">{{ t('appointments.move.now') }}:</span> {{ now }}
            </p>
            <p v-if="refusal" role="alert" class="rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-fg" data-move-refusal>{{ refusal }}</p>

            <UiSelect v-if="placeChoices.length > 1" v-model="place" :label="t('appointments.list.place')" :options="placeChoices" :error="fieldError('location')" class="sm:max-w-sm" data-move-place />
            <p v-else-if="!placeChoices.length && setup" class="text-sm text-muted" data-move-no-place>{{ t('appointments.book.noPlace') }}</p>

            <section v-if="place" class="space-y-2">
                <h3 class="text-sm font-medium text-fg">{{ t('appointments.move.when') }}</h3>
                <AvailabilityWeek ref="week" v-model:chosen="chosen" :place-id="place" :service="service" :days-ahead="setup?.appointments.days_ahead ?? 30" :except="appointment.id" :current="current" :start-on="appointment.date" selectable />
                <p v-if="fieldError('date', 'time')" class="text-sm text-danger" data-move-when-error>{{ fieldError('date', 'time') }}</p>
            </section>
        </div>
        <template #footer>
            <div class="flex w-full flex-wrap items-center justify-end gap-2">
                <p v-if="chosen" class="mr-auto text-sm max-sm:basis-full" :class="same ? 'text-muted' : 'text-fg'" data-move-chosen>{{ same ? t('appointments.move.same') : chosenText }}</p>
                <UiButton variant="secondary" class="whitespace-nowrap" @click="open = false">{{ t('common.cancel') }}</UiButton>
                <UiButton :disabled="!chosen || same" :loading="saving" class="whitespace-nowrap" data-move-submit @click="move">{{ t('appointments.move.submit') }}</UiButton>
            </div>
        </template>
    </UiDialog>
</template>
