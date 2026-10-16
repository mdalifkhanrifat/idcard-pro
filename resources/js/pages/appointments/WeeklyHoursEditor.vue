<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Copy, Plus, X } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import { copyToOpenDays, endShown, endStored, hoursMistakes, type WeeklyHours } from '@/lib/appointments/places';

/**
 * A place's week: each day open or closed, with up to `periodsPerDay` opening periods (e.g. 9–1 and 2–5); a day's
 * periods can be copied to the other open days. Mistakes show at once (as the server checks them: a period ends
 * after it starts, periods of a day do not overlap); the server's own errors too. An end of 00:00 is midnight.
 */
const props = defineProps<{ weekdays: string[]; periodsPerDay: number; errors?: Record<string, string[]>; disabled?: boolean }>();
const hours = defineModel<WeeklyHours>({ required: true });
const { t, locale } = useI18n();

/** Day names in the reader's language, from the browser (1 January 2024 was a Monday; weekdays are ISO). */
const dayName = (day: string) => {
    const iso = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].indexOf(day);

    return iso < 0 ? day : new Intl.DateTimeFormat(locale.value === 'bn' ? 'bn-BD' : locale.value, { weekday: 'long' }).format(new Date(2024, 0, 1 + iso));
};
const mistakes = computed(() => hoursMistakes(hours.value, props.weekdays));
/** A day's messages: its own mistakes first, then the server's for it or its periods. */
function messages(day: string): string[] {
    const local = Object.entries(mistakes.value).filter(([key]) => key === `hours.${day}` || key.startsWith(`hours.${day}.`)).map(([, key]) => t(`appointments.places.hours.${key}`));
    const server = Object.entries(props.errors ?? {}).filter(([key]) => key === `hours.${day}` || key.startsWith(`hours.${day}.`)).flatMap(([, list]) => list);

    return [...new Set([...local, ...server])];
}

function setDay(day: string, periods: [string, string][]): void {
    hours.value = { ...hours.value, [day]: periods };
}
function toggle(day: string, open: boolean): void {
    setDay(day, open ? [['09:00', '17:00']] : []);
}
function setTime(day: string, index: number, end: boolean, value: string): void {
    setDay(day, hours.value[day].map((period, at) => (at === index ? (end ? [period[0], endStored(value)] : [value, period[1]]) : period)) as [string, string][]);
}
function addPeriod(day: string): void {
    const last = hours.value[day].at(-1)?.[1] ?? '09:00';
    const [h, m] = last.split(':').map(Number);
    const start = last === '24:00' ? '23:00' : last;
    const end = h >= 23 ? '24:00' : `${String(h + 1).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
    setDay(day, [...hours.value[day], [start, end]]);
}
function removePeriod(day: string, index: number): void {
    setDay(day, hours.value[day].filter((_, at) => at !== index));
}
</script>

<template>
    <fieldset :disabled="disabled" class="divide-y divide-line rounded-lg border border-line" data-hours-editor>
        <div v-for="day in weekdays" :key="day" class="flex flex-col gap-2 px-3 py-3 sm:flex-row sm:items-start sm:gap-4" :data-day="day">
            <label class="flex w-36 shrink-0 cursor-pointer items-center gap-2 pt-1.5 text-sm">
                <input type="checkbox" class="size-4 rounded border-line accent-[var(--brand)]" :checked="hours[day]?.length > 0" data-day-open @change="toggle(day, ($event.target as HTMLInputElement).checked)" />
                <span class="font-medium text-fg">{{ dayName(day) }}</span>
            </label>
            <div class="min-w-0 flex-1 space-y-2">
                <p v-if="!hours[day]?.length" class="pt-1.5 text-sm text-muted">{{ t('appointments.places.hours.closed') }}</p>
                <div v-for="(period, index) in hours[day]" :key="index" class="flex flex-wrap items-center gap-2" data-period>
                    <input
                        type="time"
                        :value="period[0]"
                        :aria-label="t('appointments.places.hours.from')"
                        class="h-9 rounded-lg border border-line bg-surface px-2 text-sm text-fg"
                        :class="mistakes[`hours.${day}.${index}`] && 'border-danger'"
                        data-from
                        @change="setTime(day, index, false, ($event.target as HTMLInputElement).value)"
                    />
                    <span class="text-muted">–</span>
                    <input
                        type="time"
                        :value="endShown(period[1])"
                        :aria-label="t('appointments.places.hours.to')"
                        class="h-9 rounded-lg border border-line bg-surface px-2 text-sm text-fg"
                        :class="mistakes[`hours.${day}.${index}`] && 'border-danger'"
                        data-to
                        @change="setTime(day, index, true, ($event.target as HTMLInputElement).value)"
                    />
                    <button type="button" class="rounded p-1 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('appointments.places.hours.removePeriod')" data-remove-period @click="removePeriod(day, index)"><X class="size-4" /></button>
                </div>
                <p v-for="message in messages(day)" :key="message" class="text-xs text-danger" data-hours-error>{{ message }}</p>
                <div v-if="hours[day]?.length" class="flex flex-wrap gap-2">
                    <UiButton v-if="hours[day].length < periodsPerDay" variant="ghost" size="sm" data-add-period @click="addPeriod(day)"><Plus class="size-4" />{{ t('appointments.places.hours.addPeriod') }}</UiButton>
                    <UiButton variant="ghost" size="sm" data-copy-day @click="hours = copyToOpenDays(hours, day)"><Copy class="size-4" />{{ t('appointments.places.hours.copy') }}</UiButton>
                </div>
            </div>
        </div>
        <p class="px-3 py-2 text-xs text-muted">{{ t('appointments.places.hours.midnight') }}</p>
    </fieldset>
</template>
