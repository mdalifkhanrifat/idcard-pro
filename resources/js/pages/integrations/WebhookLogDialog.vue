<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RefreshCw, RotateCcw } from 'lucide-vue-next';
import ListPagination from '@/components/ListPagination.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import WebhookDeliveryDetail from './WebhookDeliveryDetail.vue';
import { useListQuery, type PageMeta } from '@/composables/useListQuery';
import { useUrlOpen } from '@/composables/useUrlOpen';
import { formatDateTime, formatNumber } from '@/lib/format';
import { deliveryTone, fetchWebhook, type WebhookDeliveryLine, type WebhookLogMeta } from '@/lib/integrations/webhooks';
import { useUiStore } from '@/stores/ui';

const props = defineProps<{ webhookId: string }>();
const emit = defineEmits<{ close: [] }>();
const { t } = useI18n();
const ui = useUiStore();

/** Drawn while it is open, so each opening starts from what the URL says. */
const list = useListQuery<WebhookDeliveryLine, PageMeta & Partial<WebhookLogMeta>>(`/webhooks/${props.webhookId}/deliveries`, { filters: { status: '', event: '' }, queryPrefix: 'webhook_log_' });
const { filters, rows, meta, loading, failed } = list;
const opened = useUrlOpen('webhook_delivery');
const name = ref('');
const filtered = computed(() => Boolean(filters.status || filters.event));
const asOptions = (items: { key: string; name: string }[] = []) => items.map((item) => ({ value: item.key, label: item.name }));

onMounted(async () => {
    try {
        name.value = (await fetchWebhook(props.webhookId)).name;
    } catch {
        // A link to a webhook that is gone: one word about it, not one more for its list
        ui.notify(t('webhooks.log.notFound'), 'error');
        emit('close');

        return;
    }
    list.load();
});
</script>

<template>
    <UiDialog :open="true" :title="t('webhooks.log.title', { name })" size="lg" full-on-phone @update:open="emit('close')">
        <div v-show="opened === null" class="space-y-3" data-webhook-log>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <UiSelect v-model="filters.status" class="flex-1" :label="t('common.status')" :placeholder="t('common.all')" :options="asOptions(meta.statuses)" data-log-status />
                <UiSelect v-model="filters.event" class="flex-1" :label="t('webhooks.log.event')" :placeholder="t('common.all')" :options="asOptions(meta.events)" data-log-event />
                <UiButton v-if="filtered" variant="ghost" @click="list.resetFilters()"><RotateCcw class="size-4" />{{ t('common.reset') }}</UiButton>
                <UiButton variant="ghost" :loading="loading" data-log-refresh @click="list.load()"><RefreshCw class="size-4" />{{ t('webhooks.log.refresh') }}</UiButton>
            </div>

            <p v-if="failed" class="text-sm text-danger" data-log-failed>{{ t('webhooks.log.loadFailed') }} <button type="button" class="underline" @click="list.load()">{{ t('webhooks.log.retry') }}</button></p>
            <ul v-if="rows.length" class="divide-y divide-line rounded-lg border border-line" :class="loading && 'opacity-50'" data-log-list>
                <li v-for="delivery in rows" :key="delivery.id">
                    <button type="button" class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-start text-sm hover:bg-surface-2" data-log-row @click="opened = delivery.id">
                        <span class="min-w-40 flex-1 break-words text-fg">{{ delivery.event.name }}</span>
                        <UiBadge :tone="deliveryTone(delivery.status.key)">{{ delivery.status.name }}</UiBadge>
                        <span class="w-full text-xs text-muted sm:w-auto">{{ formatDateTime(delivery.created_at) }} · {{ t('webhooks.log.attempts', { count: formatNumber(delivery.attempts) }, delivery.attempts) }}</span>
                        <span v-if="delivery.failure" class="w-full break-words text-xs text-danger" data-log-failure>{{ delivery.failure.name }}</span>
                    </button>
                </li>
            </ul>
            <UiEmpty v-else-if="name && !loading && !failed" :title="filtered ? t('common.noResults') : t('webhooks.log.empty')" :hint="filtered ? t('common.noResultsHint') : t('webhooks.log.emptyHint')" data-log-empty />
            <ListPagination :list="list" />
        </div>

        <WebhookDeliveryDetail v-if="opened !== null" :webhook-id="webhookId" :delivery-id="opened" @back="opened = null" @open="(id) => (opened = id)" @changed="list.load()" />

        <template #footer>
            <UiButton variant="secondary" @click="emit('close')">{{ t('common.close') }}</UiButton>
        </template>
    </UiDialog>
</template>
