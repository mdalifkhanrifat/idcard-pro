<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PageTabs from '@/components/PageTabs.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import { useUrlTab } from '@/composables/useUrlTab';
import HrSyncTab from './HrSyncTab.vue';
import IntegrationAccountsTab from './IntegrationAccountsTab.vue';
import WebhooksTab from './WebhooksTab.vue';

const { t } = useI18n();

type TabKey = 'accounts' | 'webhooks' | 'sync';
const TABS = computed<TabKey[]>(() => ['accounts', 'webhooks', 'sync']);
// Each list's filters leave the URL with it
const tab = useUrlTab(TABS, (key) => (key.startsWith('webhook_') ? 'webhooks' : key.startsWith('sync_') ? 'sync' : key.startsWith('f_') || ['page', 'sort', 'per_page'].includes(key) ? 'accounts' : undefined));
</script>

<template>
    <div>
        <UiPageHeader :title="t('integrations.title')" :subtitle="t('integrations.subtitle')" />
        <PageTabs v-model="tab" :tabs="TABS" :label="(key) => t(`integrations.tabs.${key}`)" />
        <IntegrationAccountsTab v-if="tab === 'accounts'" />
        <WebhooksTab v-else-if="tab === 'webhooks'" />
        <HrSyncTab v-else />
    </div>
</template>
