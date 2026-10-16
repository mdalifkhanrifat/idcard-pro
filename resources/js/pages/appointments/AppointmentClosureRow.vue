<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { CalendarOff, Pencil, Repeat, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import { formatNumber, localDigits } from '@/lib/format';
import { closureDates, closureDays, type AppointmentClosure } from '@/lib/appointments/closures';

/**
 * One closure in a list: its days (a yearly one without its year, "since" its first year; a range with its
 * length), its place or every place, whether it is over, and its reasons in both languages.
 */
const props = defineProps<{ closure: AppointmentClosure; canEdit: boolean; today: string }>();
const emit = defineEmits<{ edit: []; remove: [] }>();
const { t, locale } = useI18n();

const days = computed(() => closureDates(props.closure, locale.value));
const length = computed(() => closureDays(props.closure.date_from, props.closure.date_to));
const over = computed(() => !props.closure.yearly && props.closure.date_to < props.today);
</script>

<template>
    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start" :data-closure="closure.reason ?? closure.id">
        <component :is="closure.yearly ? Repeat : CalendarOff" class="hidden size-5 shrink-0 text-muted sm:block" />
        <div class="min-w-0 flex-1 space-y-1">
            <p class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-fg" data-closure-days>{{ days }}</span>
                <span v-if="closure.yearly" class="text-xs text-muted">{{ t('appointments.closures.since', { year: localDigits(closure.date_from.slice(0, 4)) }) }}</span>
                <span v-else-if="length > 1" class="text-xs text-muted">{{ t('appointments.closures.days', { count: formatNumber(length) }) }}</span>
                <UiBadge :tone="closure.location ? 'brand' : 'neutral'" data-closure-place>
                    <span class="max-w-48 truncate" dir="auto" :title="closure.location?.name">{{ closure.location?.name ?? t('appointments.closures.allPlaces') }}</span>
                </UiBadge>
                <UiBadge v-if="over" tone="warning" data-closure-over>{{ t('appointments.closures.over') }}</UiBadge>
            </p>
            <p v-if="closure.reason || closure.reason_local" class="flex flex-wrap gap-x-3 text-sm">
                <span v-if="closure.reason" class="break-words text-fg" dir="auto">{{ closure.reason }}</span>
                <span v-if="closure.reason_local" class="break-words text-muted" dir="auto">{{ closure.reason_local }}</span>
            </p>
            <p v-else class="text-sm text-muted">{{ t('appointments.closures.noReason') }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-1">
            <UiButton variant="ghost" size="sm" data-edit-closure @click="emit('edit')"><Pencil class="size-4" />{{ canEdit ? t('appointments.closures.edit') : t('appointments.closures.view') }}</UiButton>
            <UiButton v-if="canEdit" variant="ghost" size="sm" :aria-label="t('appointments.closures.delete')" data-delete-closure @click="emit('remove')"><Trash2 class="size-4 text-danger" /></UiButton>
        </div>
    </li>
</template>
