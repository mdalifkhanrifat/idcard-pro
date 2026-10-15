<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle } from 'lucide-vue-next';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { formatNumber } from '@/lib/format';
import type { NotificationOptions, NotificationSettings } from '@/lib/notifications/api';
import NotificationSummary from '@/pages/notifications/NotificationSummary.vue';
import OrderedChoice from '@/pages/notifications/OrderedChoice.vue';

/**
 * The organization's notifications as a whole: on or off, the channels and languages (in order), the languages of
 * an SMS, and whether old messages leave the log. All choices come from the registry (options).
 */
const props = defineProps<{ options: NotificationOptions; errors: Record<string, string[]>; disabled?: boolean }>();
const settings = defineModel<NotificationSettings>({ required: true });
const { t } = useI18n();

/** The server's first message for a field or anything inside it (e.g. notifications.channels.0). */
const error = (field: string) => Object.entries(props.errors).find(([key]) => key === `notifications.${field}` || key.startsWith(`notifications.${field}.`))?.[1];

const choices = (keys: string[], labels: Record<string, string>) => keys.map((value) => ({ value, label: labels[value] ?? value }));
const days = computed({
    get: () => settings.value.log_days,
    set: (value: number | string | null) => (settings.value.log_days = Number(value)),
});
</script>

<template>
    <UiCard :title="t('notifications.general.title')" :description="t('notifications.general.description')" data-general-card>
        <NotificationSummary :settings="settings" :options="options" />

        <fieldset :disabled="disabled" class="mt-5 space-y-6 border-t border-line pt-5">
            <UiCheckbox v-model="settings.enabled" :label="t('notifications.general.enabled')" :description="t('notifications.general.enabledHint')" data-setting="enabled" />

            <div class="grid gap-6 md:grid-cols-2">
                <div class="space-y-1.5">
                    <p class="text-sm font-medium text-fg">{{ t('notifications.general.channels') }}</p>
                    <OrderedChoice v-model="settings.channels" name="channels" :options="choices(options.channels, options.labels.channels)" :disabled="disabled" />
                    <p class="text-xs text-muted">{{ t('notifications.general.channelsHint') }}</p>
                    <p v-if="error('channels')" class="text-xs text-danger" data-error="channels">{{ error('channels')?.[0] }}</p>
                </div>

                <div class="space-y-1.5">
                    <p class="text-sm font-medium text-fg">{{ t('notifications.general.languages') }}</p>
                    <OrderedChoice v-model="settings.languages" name="languages" :options="choices(options.languages, options.labels.languages)" :min="1" :disabled="disabled" />
                    <p class="text-xs text-muted">{{ t('notifications.general.languagesHint') }}</p>
                    <p v-if="error('languages')" class="text-xs text-danger" data-error="languages">{{ error('languages')?.[0] }}</p>
                </div>
            </div>

            <div v-if="settings.languages.length > 1" class="space-y-1.5" data-setting="sms_languages">
                <p class="text-sm font-medium text-fg">{{ t('notifications.general.smsLanguages') }}</p>
                <div class="space-y-2">
                    <label v-for="mode in options.sms_languages" :key="mode" class="flex cursor-pointer items-start gap-3 text-sm">
                        <input v-model="settings.sms_languages" type="radio" name="sms_languages" :value="mode" class="mt-0.5 size-4 accent-[var(--brand)]" />
                        <span class="text-fg">{{ options.labels.sms_languages[mode] ?? mode }}</span>
                    </label>
                </div>
                <p class="text-xs text-muted">{{ t('notifications.general.smsLanguagesHint') }}</p>
                <p v-if="error('sms_languages')" class="text-xs text-danger">{{ error('sms_languages')?.[0] }}</p>
            </div>

            <div class="space-y-3 border-t border-line pt-5">
                <UiCheckbox
                    v-model="settings.prune_log"
                    :label="t('notifications.general.prune')"
                    :description="settings.prune_log ? '' : t('notifications.general.keepHint')"
                    data-setting="prune_log"
                />
                <div v-if="settings.prune_log" class="space-y-3 rounded-lg border border-warning/40 bg-warning/8 p-3">
                    <p class="flex items-start gap-2 text-sm text-fg"><AlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />{{ t('notifications.general.pruneHint', { days: formatNumber(settings.log_days) }) }}</p>
                    <div class="max-w-48">
                        <UiInput
                            v-model="days"
                            type="number"
                            :min="String(options.log_days.min)"
                            :max="String(options.log_days.max)"
                            :label="t('notifications.general.days')"
                            :error="error('log_days')"
                            data-setting="log_days"
                        />
                    </div>
                    <p class="text-xs text-muted">{{ t('notifications.general.daysHint', { min: formatNumber(options.log_days.min), max: formatNumber(options.log_days.max) }) }}</p>
                </div>
            </div>
        </fieldset>
    </UiCard>
</template>
