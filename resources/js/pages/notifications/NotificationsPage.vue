<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Eye } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import { fetchNotificationSettings, labelsOf, type NotificationSettingsResponse } from '@/lib/notifications/api';
import NotificationSummary from '@/pages/notifications/NotificationSummary.vue';
import { useAuthStore } from '@/stores/auth';

const { t } = useI18n();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

/** Read only for those who see the sent messages but may not change the organization's settings. */
const canEdit = computed(() => auth.can('settings.update'));

/** The sent messages' tab joins in 5.2f; a single tab draws no tab bar. */
const TABS = ['settings'] as const;
type TabKey = (typeof TABS)[number];
const tabFromUrl = (): TabKey => ((TABS as readonly string[]).includes(String(route.query.tab)) ? (route.query.tab as TabKey) : 'settings');
const tab = ref<TabKey>(tabFromUrl());
// The tab is in the URL (?tab=), so a link or a reload opens it; the first tab keeps the URL plain
watch(tab, (value) => router.replace({ query: { ...route.query, tab: value === TABS[0] ? undefined : value } }));
watch(() => route.query.tab, () => (tab.value = tabFromUrl()));

const data = ref<NotificationSettingsResponse | null>(null);
const loading = ref(false);
const failed = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    try {
        data.value = await fetchNotificationSettings();
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

/** Every event of the registry, as the organization set it. */
const events = computed(() => {
    const current = data.value;
    if (!current) {
        return [];
    }

    return Object.entries(current.options.events).map(([key, option]) => {
        const settings = current.notifications.events[key];
        const templates = Object.values(settings?.templates ?? {});

        return {
            key,
            name: option.name,
            description: option.description,
            enabled: !!settings?.enabled,
            channels: labelsOf(settings?.channels ?? [], current.options.labels.channels),
            ownTexts: templates.some((texts) => Object.values(texts).some((text) => text !== null)),
        };
    });
});
</script>

<template>
    <div>
        <UiPageHeader :title="t('nav.notifications')" :subtitle="t('notifications.subtitle')" />

        <p v-if="!canEdit" class="mb-4 flex items-start gap-2 rounded-lg border border-line bg-surface-2 px-4 py-3 text-sm text-muted" data-read-only>
            <Eye class="mt-0.5 size-4 shrink-0" />{{ t('notifications.readOnly') }}
        </p>

        <div v-if="TABS.length > 1" role="tablist" class="mb-6 flex gap-1 overflow-x-auto border-b border-line">
            <button
                v-for="key in TABS"
                :key="key"
                type="button"
                role="tab"
                :aria-selected="tab === key"
                class="-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap"
                :class="tab === key ? 'border-brand-ink font-medium text-brand-ink' : 'border-transparent text-muted hover:text-fg'"
                @click="tab = key"
            >
                {{ t(`notifications.tabs.${key}`) }}
            </button>
        </div>

        <p v-if="loading && !data" class="py-10 text-center text-sm text-muted">{{ t('common.loading') }}</p>
        <UiCard v-else-if="failed" :padded="false">
            <UiEmpty :title="t('notifications.loadFailed')" :hint="t('common.error')">
                <UiButton variant="secondary" :loading="loading" @click="load">{{ t('notifications.retry') }}</UiButton>
            </UiEmpty>
        </UiCard>

        <div v-else-if="data && tab === 'settings'" class="space-y-6" data-tab="settings">
            <UiCard :title="t('notifications.summary.title')" :description="t('notifications.summary.description')">
                <NotificationSummary :settings="data.notifications" :options="data.options" />
            </UiCard>

            <UiCard :title="t('notifications.events.title')" :description="t('notifications.events.description')" :padded="false">
                <ul class="divide-y divide-line">
                    <li v-for="event in events" :key="event.key" class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-start sm:justify-between" :data-event="event.key">
                        <div class="min-w-0">
                            <p class="font-medium text-fg">{{ event.name }}</p>
                            <p class="mt-0.5 text-sm text-muted">{{ event.description }}</p>
                            <p v-if="event.channels.length" class="mt-1 text-xs text-muted">{{ event.channels.join(', ') }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-1.5">
                            <UiBadge :tone="event.enabled ? 'success' : 'neutral'">{{ event.enabled ? t('notifications.events.on') : t('notifications.events.off') }}</UiBadge>
                            <UiBadge :tone="event.ownTexts ? 'brand' : 'neutral'">{{ event.ownTexts ? t('notifications.events.ownTexts') : t('notifications.events.defaultTexts') }}</UiBadge>
                        </div>
                    </li>
                </ul>
            </UiCard>
        </div>
    </div>
</template>
