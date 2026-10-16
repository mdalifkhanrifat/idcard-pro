<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiBadge from '@/components/ui/UiBadge.vue';
import { formatNumber } from '@/lib/format';
import type { AppointmentOptions, AppointmentSettings } from '@/lib/appointments/api';

/** The appointments at a glance: on or off, who books, what is booked, how it is confirmed (all names from the server). */
const props = defineProps<{ settings: AppointmentSettings; options: AppointmentOptions }>();
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
    </dl>
</template>
