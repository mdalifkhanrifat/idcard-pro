<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RefreshCw, RotateCw, SlidersHorizontal } from 'lucide-vue-next';
import ListPagination from '@/components/ListPagination.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useListQuery, type PageMeta } from '@/composables/useListQuery';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { NotificationLogOptions, NotificationMessage } from '@/lib/notifications/api';
import NotificationMessageDialog from '@/pages/notifications/NotificationMessageDialog.vue';
import { statusTone } from '@/pages/notifications/messages';
import { useAuthStore } from '@/stores/auth';

/**
 * The log of messages sent or to be sent, newest first, with its filters (named by the server, so new events and
 * channels appear by themselves); a row opens the message in full. `fixed` narrows it for good (e.g. one
 * cardholder's messages on their page) without showing in the URL; `queryPrefix` keeps its keys apart there.
 */
const props = defineProps<{ fixed?: Record<string, string>; queryPrefix?: string; languageNames: Record<string, string> }>();
const { t } = useI18n();
const auth = useAuthStore();

const list = useListQuery<NotificationMessage, PageMeta & Partial<NotificationLogOptions>>('/notifications/messages', {
    filters: { search: '', status: '', channel: '', event: '', date_from: '', date_to: '' },
    sort: '-created_at',
    perPage: 25,
    queryPrefix: props.queryPrefix,
    extraParams: () => Object.fromEntries(Object.entries(props.fixed ?? {}).map(([key, value]) => [`filter[${key}]`, value])),
});
onMounted(list.load);

const choices = (named: { key: string; name: string }[] | undefined) => (named ?? []).map((item) => ({ value: item.key, label: item.name }));
const sorts = computed(() => [
    { value: '-created_at', label: t('notifications.messages.sorts.newest') },
    { value: 'created_at', label: t('notifications.messages.sorts.oldest') },
    { value: '-sent_at', label: t('notifications.messages.sorts.sent') },
]);
const timeZone = computed(() => auth.tenant?.timezone);
const filtered = computed(() => Object.values(list.filters).some((value) => value !== ''));
/** On a phone the filters other than the search open on demand, so the messages come first. */
const moreFilters = ref(false);
const activeFilters = computed(() => Object.entries(list.filters).filter(([key, value]) => key !== 'search' && value !== '').length);

const opened = ref<string | null>(null);
const dialogOpen = computed({ get: () => opened.value !== null, set: (open: boolean) => !open && (opened.value = null) });
</script>

<template>
    <div class="rounded-xl border border-line bg-surface" data-message-list>
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-4">
            <UiInput v-model="list.filters.search" type="search" :label="t('common.search')" :placeholder="t('notifications.messages.searchHint')" class="sm:col-span-2" data-filter="search" />
            <button type="button" class="inline-flex items-center gap-2 justify-self-start text-sm text-brand-ink sm:hidden" :aria-expanded="moreFilters" data-more-filters @click="moreFilters = !moreFilters">
                <SlidersHorizontal class="size-4" />{{ t('common.filters') }}<template v-if="activeFilters"> ({{ formatNumber(activeFilters) }})</template>
            </button>
            <div class="contents" :class="!moreFilters && 'max-sm:hidden'">
            <UiSelect v-model="list.filters.status" :label="t('notifications.messages.status')" :placeholder="t('common.all')" :options="choices(list.meta.value.statuses)" data-filter="status" />
            <UiSelect v-model="list.filters.channel" :label="t('notifications.messages.channel')" :placeholder="t('common.all')" :options="choices(list.meta.value.channels)" data-filter="channel" />
            <UiSelect v-model="list.filters.event" :label="t('notifications.messages.event')" :placeholder="t('common.all')" :options="choices(list.meta.value.events)" data-filter="event" />
            <UiInput v-model="list.filters.date_from" type="date" :label="t('common.from')" data-filter="date_from" />
            <UiInput v-model="list.filters.date_to" type="date" :min="list.filters.date_from || undefined" :error="list.filterError('date_to')" :label="t('common.to')" data-filter="date_to" />
            <UiSelect v-model="list.sort.value" :label="t('notifications.messages.sort')" :options="sorts" data-filter="sort" />
            </div>
            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4 lg:justify-end">
                <UiButton v-if="filtered" variant="ghost" size="sm" @click="list.resetFilters()">{{ t('common.reset') }}</UiButton>
                <UiButton variant="secondary" size="sm" :loading="list.loading.value" data-refresh @click="list.load()"><RefreshCw class="size-4" />{{ t('notifications.messages.refresh') }}</UiButton>
            </div>
        </div>

        <ul class="divide-y divide-line" :class="list.loading.value && 'opacity-50'">
            <li v-for="row in list.rows.value" :key="row.id">
                <button type="button" class="flex w-full flex-col gap-1.5 px-5 py-3 text-left text-sm hover:bg-surface-2 sm:flex-row sm:items-center sm:gap-4" :data-row="row.id" @click="opened = row.id">
                    <UiBadge :tone="statusTone(row.status.key)" class="w-fit justify-center sm:w-24">{{ row.status.name }}</UiBadge>
                    <span class="min-w-0 flex-1">
                        <span class="block text-fg">
                            <span class="font-medium">{{ row.event.name }}</span> · {{ row.channel.name }}
                            <span v-if="row.resent_as" class="ml-1 inline-flex items-center gap-1 text-xs text-muted"><RotateCw class="size-3" />{{ t('notifications.messages.wasResent') }}</span>
                            <span v-else-if="row.resend_of" class="ml-1 text-xs text-muted">({{ t('notifications.messages.isResend') }})</span>
                        </span>
                        <span class="block text-xs break-words text-muted">
                            {{ row.recipient?.name ?? t('notifications.messages.recordGone') }}<template v-if="row.address"> · {{ row.address }}</template>
                            <template v-if="row.reason"> · {{ row.reason.name }}</template>
                        </span>
                    </span>
                    <time class="text-xs whitespace-nowrap text-muted">{{ formatDateTime(row.created_at, timeZone) }}</time>
                </button>
            </li>
        </ul>
        <UiEmpty v-if="!list.loading.value && !list.rows.value.length" :title="filtered ? t('common.noResults') : t('notifications.messages.none')" :hint="filtered ? t('common.noResultsHint') : t('notifications.messages.noneHint')" />
        <ListPagination :list="list" />

        <NotificationMessageDialog v-if="opened" v-model:open="dialogOpen" :message-id="opened" :language-names="languageNames" @resent="list.load()" />
    </div>
</template>
