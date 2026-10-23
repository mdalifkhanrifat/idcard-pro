<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import { formatNumber, localDigits } from '@/lib/format';
import { toApiError } from '@/lib/http';
import { addDays, fetchAvailability, todayIn, wallClock, type Availability, type AvailabilityQuery, type AvailableDay } from '@/lib/appointments/availability';
import { useAuthStore } from '@/stores/auth';

/**
 * A week of a place's days for a kind of visit, on the organization's wall clock: closed (with the closure's
 * reason), or the times still free (the day's places when a day is booked); earlier and later weeks within the
 * days bookings open. Shown as it is (the settings' preview) or to choose a time or a day from (booking, moving):
 * `v-model:chosen` is then the day and the time ("HH:MM", null for a day booked). `except`: an appointment being
 * moved, whose own place is shown free (and `current` marks its time; `startOn` opens on its week).
 */
const props = withDefaults(defineProps<{
    placeId: string | null;
    service: string;
    audience?: string;
    preview?: boolean;
    daysAhead: number;
    selectable?: boolean;
    except?: string | null;
    /** The time an appointment being moved has now, marked among the free ones. */
    current?: { date: string; time: string | null } | null;
    /** The first day shown (e.g. a moved appointment's own day), when not before today. */
    startOn?: string | null;
    /** Where the free times come from (the public link has its own); the staff's API when left out. */
    fetcher?: ((placeId: string, query: AvailabilityQuery) => Promise<Availability>) | null;
    /** The organization's time zone when the page has no signed-in organization (the public link). */
    timeZone?: string | null;
}>(), {
    fetcher: null,
    timeZone: null,
    audience: 'staff',
    preview: false,
    selectable: false,
    except: null,
    current: null,
    startOn: null,
});
const chosen = defineModel<{ date: string; time: string | null } | null>('chosen', { default: null });
const { t, locale } = useI18n();
const auth = useAuthStore();

const WEEK = 7;
const today = computed(() => todayIn(props.timeZone ?? auth.tenant?.timezone));
const lastBookable = computed(() => addDays(today.value, props.daysAhead));
const firstDay = () => (props.startOn && props.startOn > today.value && props.startOn <= lastBookable.value ? props.startOn : today.value);
const start = ref(firstDay());
const result = ref<Availability | null>(null);
const loading = ref(false);
const failure = ref<string | null>(null);

let asked = 0;
async function load(): Promise<void> {
    if (!props.placeId || !props.service) {
        result.value = null;
        return;
    }
    const ask = ++asked;
    loading.value = true;
    failure.value = null;
    try {
        const answer = await (props.fetcher ?? fetchAvailability)(props.placeId, { service: props.service, from: start.value, to: addDays(start.value, WEEK - 1), audience: props.audience, preview: props.preview, except: props.except });
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
watch(() => [props.placeId, props.service, props.audience, props.preview, props.except], () => {
    start.value = firstDay();
    load();
}, { immediate: true });
watch(start, load);
defineExpose({ reload: load });

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
const isChosen = (date: string, time: string | null) => chosen.value?.date === date && chosen.value.time === time;
const isCurrent = (date: string, time: string | null) => props.current?.date === date && props.current.time === time;
</script>

<template>
    <div class="space-y-3" data-week>
        <div class="flex items-center justify-between gap-2">
            <UiButton variant="secondary" size="sm" :disabled="start <= today || loading" :aria-label="t('appointments.preview.earlier')" data-week-earlier @click="start = addDays(start, -WEEK) < today ? today : addDays(start, -WEEK)">
                <ChevronLeft class="size-4" /><span class="max-sm:sr-only">{{ t('appointments.preview.earlier') }}</span>
            </UiButton>
            <p class="text-center text-sm font-medium text-fg" data-week-range>{{ range }}</p>
            <UiButton variant="secondary" size="sm" :disabled="addDays(start, WEEK) > lastBookable || loading" :aria-label="t('appointments.preview.later')" data-week-later @click="start = addDays(start, WEEK)">
                <span class="max-sm:sr-only">{{ t('appointments.preview.later') }}</span><ChevronRight class="size-4" />
            </UiButton>
        </div>

        <p v-if="failure" class="rounded-lg border border-danger/40 px-4 py-3 text-sm text-danger" data-week-failed>{{ failure }}</p>
        <p v-else-if="loading && !result" class="py-10 text-center text-sm text-muted">{{ t('common.loading') }}</p>
        <p v-else-if="result?.unavailable" class="rounded-lg border border-line bg-surface-2 px-4 py-6 text-center text-sm text-muted" data-week-unavailable>{{ result.unavailable.message }}</p>
        <p v-else-if="result && !result.days.length" class="rounded-lg border border-line px-4 py-6 text-center text-sm text-muted" data-week-beyond>
            {{ t('appointments.preview.beyond', { days: formatNumber(daysAhead) }) }}
        </p>
        <ul v-else-if="result" class="divide-y divide-line rounded-lg border border-line" :class="loading && 'opacity-60'" data-week-days>
            <li v-for="day in result.days" :key="day.date" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-start" :data-week-day="day.date">
                <p class="w-32 shrink-0 text-sm font-medium" :class="day.open ? 'text-fg' : 'text-muted'">
                    {{ dayName(day.date) }}<span v-if="day.date === today" class="ml-1 text-xs font-normal text-muted">· {{ t('appointments.preview.today') }}</span>
                </p>
                <div class="min-w-0 flex-1 text-sm">
                    <p v-if="!day.open" class="break-words text-muted" dir="auto">{{ closedText(day) }}</p>
                    <template v-else-if="result.scheduling === 'slot'">
                        <p v-if="!day.slots?.length" class="text-muted">{{ noneLeft(day) }}</p>
                        <ul v-else class="flex flex-wrap gap-1.5" data-week-slots>
                            <li v-for="slot in day.slots" :key="slot.start">
                                <component
                                    :is="selectable && slot.available > 0 ? 'button' : 'span'"
                                    :type="selectable && slot.available > 0 ? 'button' : undefined"
                                    class="inline-block rounded-md border px-2 py-0.5 text-xs tabular-nums"
                                    :class="[
                                        slot.available > 0 ? 'border-line text-fg' : 'border-line bg-surface-2 text-muted line-through',
                                        selectable && slot.available > 0 && 'hover:border-brand',
                                        isChosen(day.date, wallClock(slot.start)) && 'border-brand bg-brand/12 font-medium text-brand-ink',
                                        isCurrent(day.date, wallClock(slot.start)) && 'border-dashed border-brand-ink',
                                    ]"
                                    :aria-pressed="selectable && slot.available > 0 ? isChosen(day.date, wallClock(slot.start)) : undefined"
                                    :title="isCurrent(day.date, wallClock(slot.start)) ? t('appointments.preview.current') : t('appointments.preview.slotFree', { free: formatNumber(slot.available), capacity: formatNumber(slot.capacity) })"
                                    :data-current="isCurrent(day.date, wallClock(slot.start)) || undefined"
                                    :data-slot="`${day.date} ${wallClock(slot.start)}`"
                                    @click="selectable && slot.available > 0 && (chosen = { date: day.date, time: wallClock(slot.start) })"
                                >
                                    {{ localDigits(wallClock(slot.start)) }}<span class="ml-1 text-muted">({{ formatNumber(slot.available) }})</span>
                                </component>
                            </li>
                        </ul>
                    </template>
                    <template v-else-if="day.available">
                        <button
                            v-if="selectable"
                            type="button"
                            class="rounded-md border px-2 py-0.5 text-sm"
                            :class="[isChosen(day.date, null) ? 'border-brand bg-brand/12 font-medium text-brand-ink' : 'border-line text-fg hover:border-brand', isCurrent(day.date, null) && 'border-dashed border-brand-ink']"
                            :data-current="isCurrent(day.date, null) || undefined"
                            :aria-pressed="isChosen(day.date, null)"
                            :data-slot="`${day.date} day`"
                            @click="chosen = { date: day.date, time: null }"
                        >{{ t('appointments.preview.dayFree', { free: formatNumber(day.available), capacity: formatNumber(day.capacity ?? 0) }) }}</button>
                        <p v-else class="text-fg">{{ t('appointments.preview.dayFree', { free: formatNumber(day.available), capacity: formatNumber(day.capacity ?? 0) }) }}</p>
                    </template>
                    <p v-else class="text-muted">{{ noneLeft(day) }}</p>
                </div>
            </li>
        </ul>
        <p v-if="result?.scheduling === 'slot' && result.days.length" class="text-xs text-muted">{{ t('appointments.preview.slotHint') }}</p>
        <p v-if="current && result?.days.length" class="text-xs text-muted" data-week-current-hint><span class="mr-1 inline-block h-3 w-5 rounded border border-dashed border-brand-ink align-middle"></span>{{ t('appointments.preview.current') }}</p>
    </div>
</template>
