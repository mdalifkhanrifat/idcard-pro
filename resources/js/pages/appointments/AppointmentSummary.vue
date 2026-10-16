<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import { formatNumber } from '@/lib/format';
import type { AppointmentOptions, AppointmentSettings, PlaceCounts } from '@/lib/appointments/api';

/**
 * The appointments at a glance: on or off, who books, what is booked, how it is confirmed (all names from the
 * server), and the places when known — warning while appointments are on and no place is.
 */
const props = defineProps<{ settings: AppointmentSettings; options: AppointmentOptions; places?: PlaceCounts }>();
const { t } = useI18n();

const label = (group: keyof AppointmentOptions['labels'], key: string) => props.options.labels[group][key] ?? key;
const bookedBy = computed(() => props.settings.booking.map((key) => label('booking', key)).join(' · ') || '—');
const booked = computed(() =>
    props.settings.scheduling === 'slot'
        ? t('appointments.summary.slot', { label: label('scheduling', 'slot'), minutes: formatNumber(props.settings.slot_minutes) })
        : label('scheduling', props.settings.scheduling),
);
</script>

<template>
    <dl class="grid gap-3 text-sm sm:grid-cols-2" data-appointment-summary>
        <div class="flex items-center gap-2 sm:col-span-2">
            <UiBadge :tone="settings.enabled ? 'success' : 'neutral'">{{ settings.enabled ? t('appointments.summary.on') : t('appointments.summary.off') }}</UiBadge>
            <span v-if="!settings.enabled" class="text-muted">{{ t('appointments.summary.offHint') }}</span>
        </div>
        <div>
            <dt class="text-xs text-muted">{{ t('appointments.summary.bookedBy') }}</dt>
            <dd class="break-words text-fg">{{ bookedBy }}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted">{{ t('appointments.summary.booked') }}</dt>
            <dd class="break-words text-fg">{{ booked }}</dd>
        </div>
        <div>
            <dt class="text-xs text-muted">{{ t('appointments.summary.confirmation') }}</dt>
            <dd class="break-words text-fg">{{ label('confirmation', settings.confirmation) }}</dd>
        </div>
        <div v-if="places" data-summary="places">
            <dt class="text-xs text-muted">{{ t('appointments.summary.places') }}</dt>
            <dd class="break-words text-fg">
                {{ places.total ? t('appointments.summary.placesCount', { active: formatNumber(places.active), total: formatNumber(places.total) }) : t('appointments.summary.noPlaces') }}
            </dd>
        </div>
        <p v-if="places && settings.enabled && !places.active" class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/8 px-3 py-2 text-fg sm:col-span-2" data-summary-no-place>
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />{{ t('appointments.places.noneActive') }}
        </p>
    </dl>
</template>
