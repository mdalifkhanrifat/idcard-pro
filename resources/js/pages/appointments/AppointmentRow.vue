<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiBadge from '@/components/ui/UiBadge.vue';
import { statusTone, timeRange, type Appointment } from '@/lib/appointments/bookings';
import { formatDate, localDigits } from '@/lib/format';

/**
 * An appointment as a list row (the Appointments page, a cardholder's tab): when — its day too where the list
 * spans days —, who, its number, kind and place, and its status; pressing it opens its details.
 */
const props = withDefaults(defineProps<{ row: Appointment; showDate?: boolean; showPlace?: boolean; showHolder?: boolean; active?: boolean }>(), {
    showDate: false,
    showPlace: true,
    showHolder: true,
    active: false,
});
defineEmits<{ open: [] }>();
const { t } = useI18n();

const time = computed(() => {
    const range = timeRange(props.row);

    return range ? localDigits(range) : t('appointments.list.inTurn');
});
const relation = (key: string) => t(`cardholders.family.relations.${key}`);
</script>

<template>
    <button
        type="button"
        class="flex w-full flex-col gap-1.5 px-5 py-3 text-left text-sm hover:bg-surface-2 focus-visible:bg-surface-2 focus-visible:outline-none sm:flex-row sm:items-center sm:gap-4"
        :class="active && 'bg-surface-2'"
        data-row-open
        @click="$emit('open')"
    >
        <span class="shrink-0 tabular-nums sm:w-36" data-when>
            <span v-if="showDate" class="block text-xs text-muted">{{ formatDate(row.date) }}</span>
            <span class="font-medium text-fg">{{ time }}</span>
        </span>
        <span class="min-w-0 flex-1">
            <span class="block break-words text-fg">
                <span class="font-medium">{{ row.family_member?.name ?? row.cardholder?.name }}</span>
                <span v-if="row.family_member" class="text-xs text-muted"> · {{ relation(row.family_member.relation) }}<template v-if="showHolder"> — {{ row.cardholder?.name }}</template></span>
            </span>
            <span class="block text-xs break-words text-muted">
                <span class="font-mono">{{ row.number }}</span> · {{ row.service.name }}<template v-if="showPlace && row.location"> · {{ row.location.name }}</template><template v-if="showHolder && row.cardholder?.employee_no"> · {{ row.cardholder.employee_no }}</template>
            </span>
        </span>
        <UiBadge :tone="statusTone(row.status.key)" class="w-fit justify-center sm:w-36" :class="row.status.key === 'cancelled' && 'line-through'" data-status>{{ row.status.name }}</UiBadge>
    </button>
</template>
