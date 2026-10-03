<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useListQuery } from '@/composables/useListQuery';
import { formatDateTime } from '@/lib/format';

interface ActivityRow {
    id: number;
    log_name: string;
    event: string;
    subject_type: string;
    causer: { name: string; email: string } | null;
    changes: { attributes?: Record<string, unknown>; old?: Record<string, unknown> } | null;
    created_at: string;
}
interface LoginRow {
    id: number;
    event: string;
    identifier: string | null;
    user: { name: string } | null;
    ip_address: string;
    user_agent: string;
    created_at: string;
}

const { t } = useI18n();
const tab = ref('changes');

const activities = useListQuery<ActivityRow>('/audit/activities', { filters: { log_name: '', event: '', date_from: '', date_to: '' }, perPage: 25 });
const logins = useListQuery<LoginRow>('/audit/logins', { filters: { event: '', identifier: '', ip_address: '', date_from: '', date_to: '' }, perPage: 25 });

onMounted(() => activities.load());
watch(tab, (value) => (value === 'logins' ? logins.load() : activities.load()));

const loginEvents = ['succeeded', 'failed', 'locked', 'blocked', 'two_factor_required', 'two_factor_failed', 'logged_out', 'password_reset'];
const tone = (event: string) =>
    ({ succeeded: 'success', created: 'success', failed: 'danger', locked: 'danger', blocked: 'danger', deleted: 'danger', two_factor_failed: 'warning', updated: 'brand' })[event] as
        | 'success' | 'danger' | 'warning' | 'brand' | undefined;

function changedFields(row: ActivityRow): string[] {
    return Object.keys(row.changes?.attributes ?? {});
}

function browser(ua: string): string {
    const match = ua.match(/(Edg|Chrome|Firefox|Safari)\/[\d.]+/);
    const os = ua.match(/\(([^;)]+)/)?.[1] ?? '';

    return [match?.[0]?.replace('Edg', 'Edge'), os].filter(Boolean).join(' · ') || ua.slice(0, 40);
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('audit.title')" :subtitle="t('audit.subtitle')" />

        <TabsRoot v-model="tab">
            <TabsList class="mb-4 inline-flex rounded-lg border border-line bg-surface p-1">
                <TabsTrigger value="changes" class="rounded-md px-4 py-1.5 text-sm text-muted data-[state=active]:bg-brand data-[state=active]:text-brand-contrast">{{ t('audit.changes') }}</TabsTrigger>
                <TabsTrigger value="logins" class="rounded-md px-4 py-1.5 text-sm text-muted data-[state=active]:bg-brand data-[state=active]:text-brand-contrast">{{ t('audit.logins') }}</TabsTrigger>
            </TabsList>

            <TabsContent value="changes" class="rounded-xl border border-line bg-surface">
                <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-4">
                    <UiSelect v-model="activities.filters.log_name" :label="t('audit.what')" :placeholder="t('common.all')" :options="['user', 'tenant'].map((v) => ({ value: v, label: v }))" />
                    <UiSelect v-model="activities.filters.event" :label="t('audit.event')" :placeholder="t('common.all')" :options="['created', 'updated', 'deleted'].map((v) => ({ value: v, label: t(`audit.events.${v}`) }))" />
                    <UiInput v-model="activities.filters.date_from" type="date" :label="t('common.from')" />
                    <UiInput v-model="activities.filters.date_to" type="date" :label="t('common.to')" />
                </div>
                <ul class="divide-y divide-line" :class="activities.loading.value && 'opacity-50'">
                    <li v-for="row in activities.rows.value" :key="row.id" class="flex flex-col gap-1 px-5 py-3 text-sm sm:flex-row sm:items-center sm:gap-4">
                        <UiBadge :tone="tone(row.event) ?? 'neutral'" class="w-fit">{{ t(`audit.events.${row.event}`) }}</UiBadge>
                        <div class="min-w-0 flex-1">
                            <p class="text-fg"><span class="font-medium">{{ row.causer?.name ?? 'System' }}</span> · {{ row.subject_type }}</p>
                            <p v-if="changedFields(row).length" class="truncate text-xs text-muted">{{ changedFields(row).join(', ') }}</p>
                        </div>
                        <time class="text-xs whitespace-nowrap text-muted">{{ formatDateTime(row.created_at) }}</time>
                    </li>
                </ul>
                <UiEmpty v-if="!activities.loading.value && !activities.rows.value.length" :title="t('common.noResults')" />
                <UiPagination v-bind="{ page: activities.meta.value.current_page, lastPage: activities.meta.value.last_page, total: activities.meta.value.total, from: activities.meta.value.from, to: activities.meta.value.to }" @change="activities.page.value = $event" />
            </TabsContent>

            <TabsContent value="logins" class="rounded-xl border border-line bg-surface">
                <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-5">
                    <UiSelect v-model="logins.filters.event" :label="t('audit.event')" :placeholder="t('common.all')" :options="loginEvents.map((v) => ({ value: v, label: t(`audit.events.${v}`) }))" />
                    <UiInput v-model="logins.filters.identifier" :label="t('audit.identifier')" />
                    <UiInput v-model="logins.filters.ip_address" :label="t('audit.ip')" />
                    <UiInput v-model="logins.filters.date_from" type="date" :label="t('common.from')" />
                    <UiInput v-model="logins.filters.date_to" type="date" :label="t('common.to')" />
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" :class="logins.loading.value && 'opacity-50'">
                        <thead class="text-left text-xs tracking-wide text-muted uppercase">
                            <tr class="border-b border-line">
                                <th class="px-5 py-3">{{ t('audit.event') }}</th>
                                <th class="px-5 py-3">{{ t('audit.who') }}</th>
                                <th class="px-5 py-3">{{ t('audit.ip') }}</th>
                                <th class="px-5 py-3">{{ t('audit.device') }}</th>
                                <th class="px-5 py-3">{{ t('audit.when') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in logins.rows.value" :key="row.id" class="border-b border-line last:border-0">
                                <td class="px-5 py-3"><UiBadge :tone="tone(row.event) ?? 'neutral'">{{ t(`audit.events.${row.event}`) }}</UiBadge></td>
                                <td class="px-5 py-3 text-fg">{{ row.user?.name ?? row.identifier ?? '—' }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-muted">{{ row.ip_address }}</td>
                                <td class="px-5 py-3 text-xs text-muted">{{ browser(row.user_agent ?? '') }}</td>
                                <td class="px-5 py-3 text-xs whitespace-nowrap text-muted">{{ formatDateTime(row.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <UiEmpty v-if="!logins.loading.value && !logins.rows.value.length" :title="t('common.noResults')" />
                <UiPagination v-bind="{ page: logins.meta.value.current_page, lastPage: logins.meta.value.last_page, total: logins.meta.value.total, from: logins.meta.value.from, to: logins.meta.value.to }" @change="logins.page.value = $event" />
            </TabsContent>
        </TabsRoot>
    </div>
</template>
