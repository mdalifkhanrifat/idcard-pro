<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, Eye, PencilLine } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import type { NotificationOptions, NotificationSamples, NotificationSettings, NotificationTexts } from '@/lib/notifications/api';
import NotificationEventOptions from '@/pages/notifications/NotificationEventOptions.vue';
import NotificationTemplateDialog from '@/pages/notifications/NotificationTemplateDialog.vue';

/**
 * Every event of the registry as the organization sets it: on or off, its channels (warned when the organization
 * does not send on them), its own settings while it is on (e.g. when "card ready" goes), and its texts in the
 * editor. Changes go to the page's draft.
 */
const props = defineProps<{ options: NotificationOptions; samples: NotificationSamples; errors: Record<string, string[]>; disabled?: boolean }>();
const settings = defineModel<NotificationSettings>({ required: true });
const { t } = useI18n();

const label = (channel: string) => props.options.labels.channels[channel] ?? channel;
const events = computed(() =>
    Object.entries(props.options.events).map(([key, option]) => {
        const event = settings.value.events[key];
        const goesOn = event.channels.filter((channel) => settings.value.channels.includes(channel));

        return {
            key,
            option,
            event,
            ownTexts: Object.values(event.templates).some((texts) => Object.values(texts).some((text) => !!text)),
            // Turned on but the organization sends on none of its channels: nothing would go
            noChannel: event.enabled && goesOn.length === 0,
            // Mistakes in its texts only (its settings show theirs at the field)
            textErrors: Object.keys(props.errors).some((field) => field.startsWith(`notifications.events.${key}.templates`)),
            // Its own settings: shown while it is on, or when one of them has a mistake
            showOptions: option.options.length > 0 && !!event.options && (event.enabled || Object.keys(props.errors).some((field) => field.startsWith(`notifications.events.${key}.options`))),
        };
    }),
);

function toggleChannel(key: string, channel: string, on: boolean): void {
    const chosen = settings.value.events[key].channels;
    // Kept in the registry's order (messages go in the organization's order anyway)
    settings.value.events[key].channels = props.options.channels.filter((item) => (item === channel ? on : chosen.includes(item)));
}

const editing = ref<string | null>(null);
const editorOpen = computed({ get: () => editing.value !== null, set: (open: boolean) => !open && (editing.value = null) });
const smsLanguages = computed(() => (settings.value.sms_languages === 'first' ? settings.value.languages.slice(0, 1) : settings.value.languages));

function applyTexts(templates: Record<string, NotificationTexts>): void {
    if (editing.value) {
        settings.value.events[editing.value].templates = templates;
    }
}
</script>

<template>
    <UiCard :title="t('notifications.events.title')" :description="t('notifications.events.description')" :padded="false" data-events-card>
        <ul class="divide-y divide-line">
            <li v-for="item in events" :key="item.key" class="space-y-3 px-5 py-4" :data-event="item.key">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-medium break-words text-fg">{{ item.option.name }}</p>
                        <p class="mt-0.5 text-sm break-words text-muted">{{ item.option.description }}</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-1.5">
                        <UiBadge :tone="item.event.enabled ? 'success' : 'neutral'" data-badge="enabled">{{ item.event.enabled ? t('notifications.events.on') : t('notifications.events.off') }}</UiBadge>
                        <UiBadge :tone="item.ownTexts ? 'brand' : 'neutral'" data-badge="texts">{{ item.ownTexts ? t('notifications.events.ownTexts') : t('notifications.events.defaultTexts') }}</UiBadge>
                    </div>
                </div>

                <fieldset :disabled="disabled" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-6">
                    <UiCheckbox v-model="item.event.enabled" :label="t('notifications.events.send')" :data-event-enabled="item.key" />
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                        <span class="text-muted">{{ t('notifications.events.channels') }}:</span>
                        <label v-for="channel in options.channels" :key="channel" class="flex cursor-pointer items-center gap-2" :data-event-channel="`${item.key}.${channel}`">
                            <input
                                type="checkbox"
                                class="size-4 rounded border-line accent-[var(--brand)]"
                                :checked="item.event.channels.includes(channel)"
                                @change="toggleChannel(item.key, channel, ($event.target as HTMLInputElement).checked)"
                            />
                            <span class="text-fg">{{ label(channel) }}</span>
                            <span v-if="item.event.channels.includes(channel) && !settings.channels.includes(channel)" class="text-xs text-warning">({{ t('notifications.events.offInOrganization') }})</span>
                        </label>
                    </div>
                </fieldset>

                <NotificationEventOptions v-if="item.showOptions && item.event.options" v-model="item.event.options" :event-key="item.key" :options="item.option.options" :errors="errors" :disabled="disabled" />

                <p v-if="item.noChannel" class="flex items-start gap-2 text-sm text-warning" data-warning="no-channel">
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />{{ t('notifications.events.noChannel') }}
                </p>
                <p v-if="item.textErrors" class="text-sm text-danger" data-warning="texts">{{ t('notifications.events.textErrors') }}</p>

                <UiButton variant="secondary" size="sm" :data-edit="item.key" @click="editing = item.key">
                    <component :is="disabled ? Eye : PencilLine" class="size-4" />{{ disabled ? t('notifications.events.view') : t('notifications.events.edit') }}
                </UiButton>
            </li>
        </ul>

        <NotificationTemplateDialog
            v-if="editing"
            v-model:open="editorOpen"
            :event-key="editing"
            :event="options.events[editing]"
            :templates="settings.events[editing].templates"
            :languages="settings.languages"
            :sms-languages="smsLanguages"
            :options="options"
            :samples="samples[editing] ?? {}"
            :errors="errors"
            :disabled="disabled"
            @apply="applyTexts"
        />
    </UiCard>
</template>
