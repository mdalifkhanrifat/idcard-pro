<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import { fetchSummary, statusTone, type DaySummary, type Named } from '@/lib/appointments/bookings';
import { formatNumber } from '@/lib/format';
import { i18n } from '@/lib/i18n';

/**
 * A week at a glance (5.5g): each day's appointments under the list's criteria — how many, and how many in each
 * status that has any — counted by the server (GET /appointments/summary). Pressing a day opens it. A newer answer
 * is never covered by an older one.
 */
const props = defineProps<{ params: Record<string, string>; today: string }>();
const emit = defineEmits<{ pick: [date: string] }>();
const { t } = useI18n();

const days = ref<DaySummary[]>([]);
const statuses = ref<Named[]>([]);
const loading = ref(false);
const failed = ref(false);
let asked = 0;

async function load(): Promise<void> {
    const ask = ++asked;
    loading.value = true;
    failed.value = false;
    try {
        const answer = await fetchSummary(props.params);
        if (ask === asked) {
            days.value = answer.days;
            statuses.value = answer.statuses;
        }
    } catch {
        if (ask === asked) {
            failed.value = true;
        }
    } finally {
        if (ask === asked) {
            loading.value = false;
        }
    }
}
watch(() => JSON.stringify(props.params), load, { immediate: true });
defineExpose({ reload: load });

const weekTotal = computed(() => days.value.reduce((sum, day) => sum + day.total, 0));
const dayName = (date: string) => {
    const [year, month, day] = date.split('-').map(Number);

    return new Intl.DateTimeFormat(i18n.global.locale.value === 'bn' ? 'bn-BD' : 'en-GB', { weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(year, month - 1, day));
};
/** The statuses a day has any of, in the registry's order. */
const shown = (day: DaySummary) => statuses.value.filter((status) => (day.statuses[status.key] ?? 0) > 0);
</script>

<template>
    <div class="border-b border-line p-4" data-week-summary>
        <div v-if="failed" class="flex flex-col items-center gap-3 py-6 text-sm text-muted" data-week-summary-failed>
            {{ t('appointments.week.failed') }}<UiButton variant="secondary" size="sm" @click="load">{{ t('appointments.retry') }}</UiButton>
        </div>
        <template v-else>
            <p class="mb-3 text-sm text-muted" data-week-total>{{ t('appointments.week.total', { count: formatNumber(weekTotal) }, weekTotal) }}</p>
            <ul class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7" :class="loading && 'opacity-60'">
                <li v-for="day in days" :key="day.date">
                    <button
                        type="button"
                        class="flex h-full w-full flex-col gap-1.5 rounded-lg border p-3 text-left hover:border-brand focus-visible:border-brand focus-visible:outline-none"
                        :class="day.date === today ? 'border-brand-ink bg-brand/5' : 'border-line'"
                        :aria-label="t('appointments.week.open', { day: dayName(day.date) })"
                        :data-week-card="day.date"
                        @click="emit('pick', day.date)"
                    >
                        <span class="text-xs font-medium" :class="day.date === today ? 'text-brand-ink' : 'text-muted'">
                            {{ dayName(day.date) }}<template v-if="day.date === today"> · {{ t('appointments.list.today') }}</template>
                        </span>
                        <span class="text-2xl font-semibold tabular-nums" :class="day.total ? 'text-fg' : 'text-muted'" data-week-card-total>{{ formatNumber(day.total) }}</span>
                        <span v-if="shown(day).length" class="flex flex-wrap gap-1">
                            <UiBadge v-for="status in shown(day)" :key="status.key" :tone="statusTone(status.key)" class="text-[11px]" :data-week-card-status="status.key">
                                {{ status.name }} {{ formatNumber(day.statuses[status.key]) }}
                            </UiBadge>
                        </span>
                    </button>
                </li>
            </ul>
        </template>
    </div>
</template>
