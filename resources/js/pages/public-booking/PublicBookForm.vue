<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { wallClock } from '@/lib/appointments/availability';
import { bookPublic, movePublic, publicAvailability, type PublicAppointment, type PublicBookingInfo, type PublicPerson } from '@/lib/appointments/public';
import { formatLongDate, localDigits } from '@/lib/format';
import { toApiError } from '@/lib/http';
import AvailabilityWeek from '@/pages/appointments/AvailabilityWeek.vue';

/**
 * Booking on the public link (5.6) — for oneself or a family member, a kind of visit offered there, a place that
 * takes it (by itself when there is one), a free time of the week, a note — or, given `moving`, moving one's own to
 * another place, day or time (its own time shown free and marked). The server checks it all again; its refusals
 * come under their fields, and a time taken meanwhile shows the week afresh.
 */
const props = defineProps<{ organization: string; info: PublicBookingInfo; person: PublicPerson; moving?: PublicAppointment | null }>();
const emit = defineEmits<{ done: [appointment: PublicAppointment]; cancel: []; 'signed-out': [] }>();
const { t } = useI18n();

const member = ref('');
const service = ref(props.moving?.service.key ?? props.info.services[0]?.key ?? '');
const place = ref('');
const chosen = ref<{ date: string; time: string | null } | null>(null);
const note = ref('');
const errors = ref<Record<string, string[]>>({});
const refusal = ref<string | null>(null);
const saving = ref(false);
const week = ref<InstanceType<typeof AvailabilityWeek> | null>(null);

const members = computed(() => [
    { value: '', label: t('publicBooking.book.myself', { name: props.person.name }) },
    ...props.person.family_members.map((m) => ({ value: m.id, label: `${m.name} · ${t(`cardholders.family.relations.${m.relation}`)}` })),
]);
const services = computed(() => props.info.services.map((s) => ({ value: s.key, label: s.name })));
const places = computed(() => props.info.places.filter((p) => p.services.includes(service.value)).map((p) => ({ value: p.id, label: p.name, address: p.address })));
watch(places, (choices) => {
    if (!choices.some((choice) => choice.value === place.value)) {
        place.value = (props.moving && choices.some((c) => c.value === props.moving?.location?.id) ? props.moving.location?.id : choices[0]?.value) ?? '';
    }
}, { immediate: true });
watch([service, place], () => (chosen.value = null));
const address = computed(() => places.value.find((p) => p.value === place.value)?.address ?? null);
const fetcher = computed(() => publicAvailability(props.organization, props.moving?.id));
const current = computed(() => (props.moving && place.value === props.moving.location?.id ? { date: props.moving.date, time: props.moving.starts_at ? wallClock(props.moving.starts_at) : null } : null));
const same = computed(() => !!current.value && !!chosen.value && current.value.date === chosen.value.date && current.value.time === chosen.value.time);
const chosenText = computed(() => {
    if (!chosen.value) {
        return '';
    }
    const date = formatLongDate(chosen.value.date, false);

    return chosen.value.time ? `${date}, ${localDigits(chosen.value.time)}` : `${date} · ${t('appointments.list.inTurn')}`;
});
const fieldError = (...keys: string[]) => keys.map((key) => errors.value[key]?.[0]).find(Boolean);

async function submit(): Promise<void> {
    if (!chosen.value || !place.value || same.value) {
        return;
    }
    saving.value = true;
    errors.value = {};
    refusal.value = null;
    try {
        const input = { location: place.value, date: chosen.value.date, time: chosen.value.time };
        emit('done', props.moving
            ? await movePublic(props.organization, props.moving.id, input)
            : await bookPublic(props.organization, { ...input, service: service.value, family_member: member.value || null, note: note.value.trim() || null }));
    } catch (failure) {
        const apiError = toApiError(failure);
        if (apiError.status === 401) {
            emit('signed-out');
            return;
        }
        errors.value = apiError.errors;
        refusal.value = fieldError('date', 'time', 'service', 'location', 'family_member', 'note') ? null : (apiError.errors.status?.[0] ?? apiError.message);
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
    <form class="space-y-5" :data-public-form="moving ? 'move' : 'book'" @submit.prevent="submit">
        <h2 class="text-base font-semibold text-fg">{{ moving ? t('publicBooking.move.title', { number: moving.number }) : t('publicBooking.book.title') }}</h2>
        <p v-if="refusal" role="alert" class="rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-fg" data-public-refusal>{{ refusal }}</p>

        <template v-if="!moving">
            <UiSelect v-if="info.family_members && person.family_members.length" v-model="member" :label="t('publicBooking.book.forWhom')" :options="members" :error="fieldError('family_member')" data-public-member />
            <UiSelect v-model="service" :label="t('appointments.list.service')" :options="services" :error="fieldError('service')" data-public-service />
        </template>
        <div v-if="places.length > 1 || address" class="space-y-1">
            <UiSelect v-if="places.length > 1" v-model="place" :label="t('appointments.list.place')" :options="places" :error="fieldError('location')" data-public-place />
            <p v-if="places.length === 1" class="text-sm text-fg" data-public-place-name><span class="text-muted">{{ t('appointments.list.place') }}:</span> {{ places[0].label }}</p>
            <p v-if="address" class="text-xs break-words text-muted" data-public-address>{{ address }}</p>
        </div>
        <p v-if="!places.length" class="text-sm text-muted" data-public-no-place>{{ t('appointments.book.noPlace') }}</p>

        <section v-if="place && service" class="space-y-2">
            <h3 class="text-sm font-medium text-fg">{{ t('appointments.book.when') }}</h3>
            <AvailabilityWeek
                ref="week"
                v-model:chosen="chosen"
                :place-id="place"
                :service="service"
                audience="public"
                :days-ahead="info.days_ahead"
                :fetcher="fetcher"
                :time-zone="info.organization.timezone"
                :current="current"
                :start-on="moving?.date ?? null"
                selectable
            />
            <p v-if="fieldError('date', 'time')" class="text-sm text-danger" data-public-when-error>{{ fieldError('date', 'time') }}</p>
        </section>

        <UiTextarea v-if="!moving" v-model="note" :label="t('appointments.book.note')" :rows="2" :maxlength="500" :error="fieldError('note')" data-public-note />

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line pt-4">
            <p v-if="chosen" class="mr-auto text-sm max-sm:basis-full" :class="same ? 'text-muted' : 'text-fg'" data-public-chosen>{{ same ? t('appointments.move.same') : chosenText }}</p>
            <UiButton variant="secondary" class="whitespace-nowrap" @click="emit('cancel')">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" class="whitespace-nowrap" :disabled="!chosen || same" :loading="saving" data-public-submit>{{ moving ? t('appointments.move.submit') : t('appointments.book.submit') }}</UiButton>
        </div>
    </form>
</template>
