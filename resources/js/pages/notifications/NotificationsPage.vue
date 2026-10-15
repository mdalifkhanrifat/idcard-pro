<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Eye } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { toApiError } from '@/lib/http';
import { fetchNotificationSettings, labelsOf, saveNotificationSettings, type NotificationSettings, type NotificationSettingsResponse } from '@/lib/notifications/api';
import NotificationGeneralCard from '@/pages/notifications/NotificationGeneralCard.vue';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();
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
/** The settings as being changed on the page; saved as a whole. */
const draft = ref<NotificationSettings | null>(null);
const loading = ref(false);
const failed = ref(false);
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});

function show(response: NotificationSettingsResponse): void {
    data.value = response;
    // A plain copy (a reactive object cannot be structured-cloned)
    draft.value = JSON.parse(JSON.stringify(response.notifications)) as NotificationSettings;
    errors.value = {};
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    try {
        show(await fetchNotificationSettings());
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const dirty = computed(() => !!data.value && !!draft.value && JSON.stringify(draft.value) !== JSON.stringify(data.value.notifications));

async function save(): Promise<void> {
    if (!draft.value) {
        return;
    }
    saving.value = true;
    errors.value = {};
    try {
        // The settings as the server saved them come back: the form shows exactly those
        show(await saveNotificationSettings(draft.value));
        ui.notify(t('common.saved'));
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.errors;
        if (!Object.keys(apiError.errors).length) {
            ui.notify(apiError.message, 'error');
        }
    } finally {
        saving.value = false;
    }
}

function discard(): void {
    if (data.value) {
        show(data.value);
    }
}

// Unsaved changes are not lost by leaving: asked first, in the app and when closing or reloading the tab
onBeforeRouteLeave(async () => !dirty.value || (await confirm({ title: t('notifications.save.leaveConfirm'), message: t('notifications.save.leaveHint'), confirmLabel: t('notifications.save.leave'), danger: true })));
function warnOnUnload(event: BeforeUnloadEvent): void {
    if (dirty.value) {
        event.preventDefault();
    }
}
window.addEventListener('beforeunload', warnOnUnload);
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnOnUnload));

/** Every event of the registry, as being set on the page. */
const events = computed(() => {
    const current = data.value;
    if (!current || !draft.value) {
        return [];
    }

    return Object.entries(current.options.events).map(([key, option]) => {
        const settings = draft.value?.events[key];
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

        <div v-else-if="data && draft && tab === 'settings'" class="space-y-6" :class="dirty && canEdit && 'pb-20'" data-tab="settings">
            <NotificationGeneralCard v-model="draft" :options="data.options" :errors="errors" :disabled="!canEdit || saving" />

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

        <!-- Stays at the bottom while there are unsaved changes -->
        <div v-if="dirty && canEdit" class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 shadow-[0_-4px_12px_rgba(16,24,40,0.08)] backdrop-blur lg:left-64" data-save-bar>
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-6">
                <p class="text-sm text-fg">{{ t('notifications.save.unsaved') }}</p>
                <div class="flex gap-2">
                    <UiButton variant="secondary" :disabled="saving" data-discard @click="discard">{{ t('notifications.save.discard') }}</UiButton>
                    <UiButton :loading="saving" data-save @click="save">{{ t('common.save') }}</UiButton>
                </div>
            </div>
        </div>
    </div>
</template>
