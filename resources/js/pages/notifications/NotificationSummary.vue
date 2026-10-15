<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import UiBadge from '@/components/ui/UiBadge.vue';
import { formatNumber } from '@/lib/format';
import { labelsOf, type NotificationOptions, type NotificationSettings } from '@/lib/notifications/api';

/** The organization's notifications at a glance: on or off, its channels and languages in order, events on. */
const props = defineProps<{ settings: NotificationSettings; options: NotificationOptions }>();
const { t } = useI18n();

const events = computed(() => Object.keys(props.options.events));
const eventsOn = computed(() => events.value.filter((key) => props.settings.events[key]?.enabled).length);
const rows = computed(() => [
    { key: 'channels', label: t('notifications.summary.channels'), value: labelsOf(props.settings.channels, props.options.labels.channels).join(', ') || t('notifications.summary.none') },
    { key: 'languages', label: t('notifications.summary.languages'), value: labelsOf(props.settings.languages, props.options.labels.languages).join(' → ') },
    { key: 'events', label: t('notifications.summary.events'), value: t('notifications.summary.eventsCount', { on: formatNumber(eventsOn.value), total: formatNumber(events.value.length) }) },
]);
</script>

<template>
    <div class="space-y-3" data-notification-summary>
        <div class="flex flex-wrap items-center gap-2">
            <UiBadge :tone="settings.enabled ? 'success' : 'neutral'" data-summary="status">{{ settings.enabled ? t('notifications.summary.on') : t('notifications.summary.off') }}</UiBadge>
            <span v-if="!settings.enabled" class="text-sm text-muted">{{ t('notifications.summary.offHint') }}</span>
        </div>
        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div v-for="row in rows" :key="row.key" class="min-w-0" :data-summary="row.key">
                <dt class="text-xs text-muted">{{ row.label }}</dt>
                <dd class="mt-0.5 break-words text-fg">{{ row.value }}</dd>
            </div>
        </dl>
    </div>
</template>
