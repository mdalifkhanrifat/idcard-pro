<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronLeft, ChevronRight, Info } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiRadioGroup from '@/components/ui/UiRadioGroup.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { formatNumber, localDigits } from '@/lib/format';
import { toApiError } from '@/lib/http';
import type { AppointmentOptions, AppointmentSettings } from '@/lib/appointments/api';
import { addDays, fetchAvailability, todayIn, wallClock, type Availability, type AvailableDay } from '@/lib/appointments/availability';
import type { AppointmentPlace } from '@/lib/appointments/places';
import { serviceName } from '@/lib/appointments/services';
import { useAuthStore } from '@/stores/auth';

/**
 * How a place will look to whoever books: a week of its days for a kind of visit — closed (with the closure's
 * reason), or the times still free (the day's places when a day is booked) — for staff or as the public link
 * shows it. By the saved settings, as they will be once appointments and the place are on (a preview), on the
 * organization's wall clock.
 */
const props = defineProps<{ place: AppointmentPlace | null; options: AppointmentOptions; settings: AppointmentSettings; unsaved: boolean }>();
const open = defineModel<boolean>('open', { default: false });
const { t, locale } = useI18n();
const auth = useAuthStore();

const WEEK = 7;
const today = computed(() => todayIn(auth.tenant?.timezone));
const lastBookable = computed(() => addDays(today.value, props.settings.days_ahead));

/** The kinds of visit the place takes that are on. */
const services = computed(() =>
    Object.entries(props.settings.services)
        .filter(([key, service]) => service.enabled && (!props.place?.services || props.place.services.includes(key)))
        .map(([key, service]) => ({ value: key, label: serviceName(key, service, props.options, locale.value) })),
);
const audiences = computed(() => props.settings.booking.map((key) => ({ value: key, label: props.options.labels.booking[key] ?? key })));

const service = ref('');
const audience = ref('staff');
const start = ref('');
const result = ref<Availability | null>(null);
const loading = ref(false);
const failure = ref<string | null>(null);

watch(open, (value) => {
    if (!value) {
        return;
    }
    service.value = services.value.find((choice) => choice.value === service.value)?.value ?? services.value[0]?.value ?? '';
    audience.value = props.settings.booking.includes(audience.value) ? audience.value : (props.settings.booking[0] ?? 'staff');
    start.value = today.value;
    load();
});
watch([service, audience, start], () => open.value && load());

let asked = 0;
async function load(): Promise<void> {
    if (!props.place || !service.value) {
        result.value = null;
        return;
    }
    const ask = ++asked;
    loading.value = true;
    failure.value = null;
    try {
        const answer = await fetchAvailability(props.place.id, { service: service.value, from: start.value, to: addDays(start.value, WEEK - 1), audience: audience.value, preview: true });
        // An older answer (a quick change of week or kind) does not overwrite a newer one
        if (ask === asked) {
            result.value = answer;
        }
    } catch (error) {
        if (ask === asked) {
            failure.value = toApiError(error).message;
            result.value = null;
        }
    } finally {
        if (ask === asked) {
            loading.value = false;
        }
    }
}

const dateFormat = computed(() => new Intl.DateTimeFormat(locale.value === 'bn' ? 'bn-BD' : 'en-GB', { weekday: 'short', day: 'numeric', month: 'short' }));
function dayName(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return dateFormat.value.format(new Date(year, month - 1, day));
}
const range = computed(() => `${dayName(start.value)} – ${dayName(addDays(start.value, WEEK - 1))}`);

/** What a day says when it has nothing to book. */
function closedText(day: AvailableDay): string {
    if (day.closure) {
        const reasons = [day.closure.reason, day.closure.reason_local].filter(Boolean).join(' · ');

        return reasons ? t('appointments.preview.closedFor', { reason: reasons }) : t('appointments.preview.closure');
    }

    return t('appointments.preview.closed');
}
const noneLeft = (day: AvailableDay) => (day.date === today.value ? t('appointments.preview.noneLeftToday') : t('appointments.preview.noneLeft'));
</script>

<template>
    <UiDialog v-model:open="open" :title="t('appointments.preview.title', { name: place?.name ?? '' })" :description="t('appointments.preview.description')" size="xl" full-on-phone>
        <div class="space-y-4" data-preview>
            <div class="space-y-2 text-xs">
                <p v-if="!settings.enabled || (place && !place.is_active)" class="flex items-start gap-2 rounded-lg border border-line bg-surface-2 px-3 py-2 text-muted" data-preview-off>
                    <Info class="mt-0.5 size-4 shrink-0" />{{ !settings.enabled ? t('appointments.preview.moduleOff') : t('appointments.preview.placeOff') }}
                </p>
                <p v-if="unsaved" class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/8 px-3 py-2 text-fg" data-preview-unsaved>
                    <Info class="mt-0.5 size-4 shrink-0 text-warning" />{{ t('appointments.preview.unsaved') }}
                </p>
            </div>

            <p v-if="!services.length" class="rounded-lg border border-line px-4 py-6 text-center text-sm text-muted" data-preview-no-service>{{ t('appointments.preview.noService') }}</p>
            <template v-else>
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiSelect v-model="service" :label="t('appointments.preview.service')" :options="services" data-preview-service />
                    <UiRadioGroup v-if="audiences.length > 1" v-model="audience" :label="t('appointments.preview.audience')" :options="audiences" data-preview-audience />
                </div>

                <div class="flex items-center justify-between gap-2">
                    <UiButton variant="secondary" size="sm" :disabled="start <= today || loading" :aria-label="t('appointments.preview.earlier')" data-preview-earlier @click="start = addDays(start, -WEEK) < today ? today : addDays(start, -WEEK)">
                        <ChevronLeft class="size-4" /><span class="max-sm:sr-only">{{ t('appointments.preview.earlier') }}</span>
                    </UiButton>
                    <p class="text-center text-sm font-medium text-fg" data-preview-range>{{ range }}</p>
                    <UiButton variant="secondary" size="sm" :disabled="addDays(start, WEEK) > lastBookable || loading" :aria-label="t('appointments.preview.later')" data-preview-later @click="start = addDays(start, WEEK)">
                        <span class="max-sm:sr-only">{{ t('appointments.preview.later') }}</span><ChevronRight class="size-4" />
                    </UiButton>
                </div>

                <p v-if="failure" class="rounded-lg border border-danger/40 px-4 py-3 text-sm text-danger" data-preview-failed>{{ failure }}</p>
                <p v-else-if="loading && !result" class="py-10 text-center text-sm text-muted">{{ t('common.loading') }}</p>
                <p v-else-if="result?.unavailable" class="rounded-lg border border-line bg-surface-2 px-4 py-6 text-center text-sm text-muted" data-preview-unavailable>{{ result.unavailable.message }}</p>
                <p v-else-if="result && !result.days.length" class="rounded-lg border border-line px-4 py-6 text-center text-sm text-muted" data-preview-beyond>
                    {{ t('appointments.preview.beyond', { days: formatNumber(settings.days_ahead) }) }}
                </p>
                <ul v-else-if="result" class="divide-y divide-line rounded-lg border border-line" :class="loading && 'opacity-60'" data-preview-days>
                    <li v-for="day in result.days" :key="day.date" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-start" :data-preview-day="day.date">
                        <p class="w-32 shrink-0 text-sm font-medium" :class="day.open ? 'text-fg' : 'text-muted'">
                            {{ dayName(day.date) }}<span v-if="day.date === today" class="ml-1 text-xs font-normal text-muted">· {{ t('appointments.preview.today') }}</span>
                        </p>
                        <div class="min-w-0 flex-1 text-sm">
                            <p v-if="!day.open" class="break-words text-muted" dir="auto">{{ closedText(day) }}</p>
                            <template v-else-if="result.scheduling === 'slot'">
                                <p v-if="!day.slots?.length" class="text-muted">{{ noneLeft(day) }}</p>
                                <ul v-else class="flex flex-wrap gap-1.5" data-preview-slots>
                                    <li
                                        v-for="slot in day.slots"
                                        :key="slot.start"
                                        class="rounded-md border px-2 py-0.5 text-xs tabular-nums"
                                        :class="slot.available > 0 ? 'border-line text-fg' : 'border-line bg-surface-2 text-muted line-through'"
                                        :title="t('appointments.preview.slotFree', { free: formatNumber(slot.available), capacity: formatNumber(slot.capacity) })"
                                    >
                                        {{ localDigits(wallClock(slot.start)) }}<span class="ml-1 text-muted">({{ formatNumber(slot.available) }})</span>
                                    </li>
                                </ul>
                            </template>
                            <p v-else-if="day.available" class="text-fg">{{ t('appointments.preview.dayFree', { free: formatNumber(day.available), capacity: formatNumber(day.capacity ?? 0) }) }}</p>
                            <p v-else class="text-muted">{{ noneLeft(day) }}</p>
                        </div>
                    </li>
                </ul>
                <p v-if="result?.scheduling === 'slot' && result.days.length" class="text-xs text-muted">{{ t('appointments.preview.slotHint') }}</p>
            </template>
        </div>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.close') }}</UiButton>
        </template>
    </UiDialog>
</template>
