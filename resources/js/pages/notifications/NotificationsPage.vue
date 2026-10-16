<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { Eye } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import PageTabs from '@/components/PageTabs.vue';
import SettingsSaveBar from '@/components/SettingsSaveBar.vue';
import { useSettingsDraft } from '@/composables/useSettingsDraft';
import { useUrlTab } from '@/composables/useUrlTab';
import { fetchNotificationSettings, saveNotificationSettings, type NotificationSettings, type NotificationSettingsResponse } from '@/lib/notifications/api';
import NotificationEventsCard from '@/pages/notifications/NotificationEventsCard.vue';
import NotificationGeneralCard from '@/pages/notifications/NotificationGeneralCard.vue';
import NotificationMessageList from '@/pages/notifications/NotificationMessageList.vue';
import NotificationTestCard from '@/pages/notifications/NotificationTestCard.vue';
import { useAuthStore } from '@/stores/auth';

const { t } = useI18n();
const auth = useAuthStore();

/** Read only for those who see the sent messages but may not change the organization's settings. */
const canEdit = computed(() => auth.can('settings.update'));
/** A test message is sent by hand. */
const canSend = computed(() => auth.can('notifications.send'));

/** The sent messages for those who may see them; a single tab draws no tab bar. */
type TabKey = 'settings' | 'messages';
const TABS = computed<TabKey[]>(() => ['settings', ...(auth.can('notifications.view') ? (['messages'] as const) : [])]);
// Leaving for the settings, the message list's filters leave the URL
const tab = useUrlTab(TABS, (key) => key.startsWith('f_') || ['page', 'sort', 'per_page'].includes(key));

const { data, draft, loading, failed, saving, errors, dirty, load, save, discard } = useSettingsDraft<NotificationSettingsResponse, NotificationSettings>({
    fetch: fetchNotificationSettings,
    store: saveNotificationSettings,
    settingsOf: (response) => response.notifications,
    leaveHint: () => t('notifications.save.leaveHint'),
});
onMounted(load);
</script>

<template>
    <div>
        <UiPageHeader :title="t('nav.notifications')" :subtitle="t('notifications.subtitle')" />

        <p v-if="!canEdit" class="mb-4 flex items-start gap-2 rounded-lg border border-line bg-surface-2 px-4 py-3 text-sm text-muted" data-read-only>
            <Eye class="mt-0.5 size-4 shrink-0" />{{ t('notifications.readOnly') }}
        </p>

        <PageTabs v-model="tab" :tabs="TABS" :label="(key) => t(`notifications.tabs.${key}`)" />

        <p v-if="loading && !data" class="py-10 text-center text-sm text-muted">{{ t('common.loading') }}</p>
        <UiCard v-else-if="failed" :padded="false">
            <UiEmpty :title="t('notifications.loadFailed')" :hint="t('common.error')">
                <UiButton variant="secondary" :loading="loading" @click="load">{{ t('notifications.retry') }}</UiButton>
            </UiEmpty>
        </UiCard>

        <NotificationMessageList v-else-if="data && tab === 'messages'" :language-names="data.options.labels.languages" :class="dirty && canEdit && 'mb-20'" data-tab="messages" />

        <div v-else-if="data && draft && tab === 'settings'" class="space-y-6" :class="dirty && canEdit && 'pb-20'" data-tab="settings">
            <NotificationGeneralCard v-model="draft" :options="data.options" :errors="errors" :disabled="!canEdit || saving" />

            <NotificationEventsCard v-model="draft" :options="data.options" :samples="data.samples" :errors="errors" :disabled="!canEdit || saving" />

            <!-- With the saved settings (its words are in the saved languages); for those who may send by hand -->
            <NotificationTestCard v-if="canSend" :options="data.options" :organization-channels="data.notifications.channels" />
        </div>

        <SettingsSaveBar v-if="dirty && canEdit" :saving="saving" @save="save" @discard="discard" />
    </div>
</template>
