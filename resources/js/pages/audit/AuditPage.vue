<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ListPagination from '@/components/ListPagination.vue';
import ReportDownloads from '@/pages/reports/ReportDownloads.vue';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useListQuery } from '@/composables/useListQuery';
import { formatDateTime } from '@/lib/format';
import { useReports } from '@/pages/reports/reports';
import { useAuthStore } from '@/stores/auth';

interface ActivityRow {
    id: number;
    log_name: string;
    event: string;
    subject_type: string;
    /** A user, or a cardholder acting for themself on the public booking link (5.6); null for the system. */
    causer: { kind: 'user' | 'cardholder'; name: string | null; email: string | null; employee_no?: string | null } | null;
    /** Field changes, or what an event recorded (e.g. requirements_overridden: moment, reason, requirements). */
    changes: { attributes?: Record<string, unknown>; old?: Record<string, unknown>; reason?: string; moment?: string; report?: string; format?: string; rows?: number; print?: boolean; messages?: number; days?: number; address?: string } | null;
    created_at: string;
}
interface LoginRow {
    id: number;
    event: string;
    identifier: string | null;
    /** Staff sign-in, or a cardholder proving who they are on the public booking link (5.6e). */
    channel: { key: string; name: string };
    user: { name: string } | null;
    cardholder: { name: string; employee_no: string | null } | null;
    ip_address: string;
    user_agent: string;
    created_at: string;
}

const { t, te, n } = useI18n();
const tab = ref('changes');
const auth = useAuthStore();
const reports = useReports();

const activities = useListQuery<ActivityRow>('/audit/activities', { filters: { log_name: '', event: '', date_from: '', date_to: '' }, perPage: 25 });
// Its own keys in the URL (login_page, login_f_event...): the changes tab's page or filters are not the sign-ins'
const logins = useListQuery<LoginRow>('/audit/logins', { filters: { event: '', channel: '', identifier: '', ip_address: '', date_from: '', date_to: '' }, perPage: 25, queryPrefix: 'login_' });
/** The ways one signs in (from the API, so new ones appear by themselves). */
const loginChannels = computed(() => ((logins.meta.value as { channels?: { key: string; name: string }[] }).channels ?? []).map((channel) => ({ value: channel.key, label: channel.name })));

onMounted(() => {
    activities.load();
    // Names of downloaded reports, in the reader's language (only for those who may see reports)
    if (auth.can('reports.view')) {
        reports.load().catch(() => undefined);
    }
});
const reportName = (key: string) => reports.find(key)?.name ?? key.replaceAll('_', ' ');

/** What a row is about in the reader's language (e.g. "Organization"), else as recorded. */
const logLabel = (name: string, fallback = name) => (te(`audit.logs.${name}`) ? t(`audit.logs.${name}`) : fallback);
/** Kinds of record with changes in this organization (from the API, so new modules appear by themselves). */
const logNames = computed(() => ((activities.meta.value as { log_names?: string[] }).log_names ?? []).map((name) => ({ value: name, label: logLabel(name) })));
/** Events that occurred (from the API, so new kinds such as requirements_overridden appear by themselves). */
const eventLabel = (event: string) => (te(`audit.events.${event}`) ? t(`audit.events.${event}`) : event);
const events = computed(() => ((activities.meta.value as { events?: string[] }).events ?? []).map((value) => ({ value, label: eventLabel(value) })));
watch(tab, (value) => (value === 'logins' ? logins.load() : activities.load()));

const loginEvents = ['succeeded', 'failed', 'locked', 'blocked', 'two_factor_required', 'two_factor_failed', 'logged_out', 'password_reset'];
const tone = (event: string) =>
    ({ succeeded: 'success', created: 'success', failed: 'danger', locked: 'danger', blocked: 'danger', deleted: 'danger', two_factor_failed: 'warning', updated: 'brand', requirements_overridden: 'warning' })[event] as
        | 'success' | 'danger' | 'warning' | 'brand' | undefined;

function changedFields(row: ActivityRow): string[] {
    return Object.keys(row.changes?.attributes ?? {});
}

function browser(ua: string): string {
    const match = ua.match(/(Edg|Chrome|Firefox|Safari)\/[\d.]+/);
    const os = ua.match(/\(([^;)]+)/)?.[1] ?? '';

    return [match?.[0]?.replace('Edg', 'Edge'), os].filter(Boolean).join(' · ') || ua.slice(0, 40);
}

const loginReportQuery = computed(() => Object.fromEntries(Object.entries(logins.filters).filter(([, value]) => value !== '').map(([key, value]) => [`f_${key}`, value])));
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
                    <UiSelect v-model="activities.filters.log_name" :label="t('audit.what')" :placeholder="t('common.all')" :options="logNames" />
                    <UiSelect v-model="activities.filters.event" :label="t('audit.event')" :placeholder="t('common.all')" :options="events" />
                    <UiInput v-model="activities.filters.date_from" type="date" :label="t('common.from')" />
                    <UiInput v-model="activities.filters.date_to" type="date" :min="activities.filters.date_from || undefined" :error="activities.filterError('date_to')" :label="t('common.to')" />
                    <div v-if="auth.can('reports.view')" class="flex items-end sm:col-span-4 sm:justify-end">
                        <ReportDownloads report-key="audit_changes" variant="menu" :params="activities.queryParams" :total="activities.meta.value.total" />
                    </div>
                </div>
                <ul class="divide-y divide-line" :class="activities.loading.value && 'opacity-50'">
                    <li v-for="row in activities.rows.value" :key="row.id" class="flex flex-col gap-1 px-5 py-3 text-sm sm:flex-row sm:items-center sm:gap-4">
                        <UiBadge :tone="tone(row.event) ?? 'neutral'" class="w-fit">{{ eventLabel(row.event) }}</UiBadge>
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-fg">
                                <span class="font-medium">{{ row.causer?.name ?? t('audit.system') }}</span>
                                <span v-if="row.causer?.kind === 'cardholder'" class="text-muted" data-audit-cardholder> ({{ t('audit.cardholderSelf') }}<template v-if="row.causer.employee_no"> · <span class="whitespace-nowrap">{{ row.causer.employee_no }}</span></template>)</span>
                                <template v-if="row.log_name || row.subject_type"> · {{ logLabel(row.log_name ?? '', row.subject_type) }}</template>
                            </p>
                            <p v-if="changedFields(row).length" class="truncate text-xs text-muted">{{ changedFields(row).join(', ') }}</p>
                            <p v-if="row.event === 'exported' && row.changes?.report" class="text-xs text-muted" data-audit-export>
                                {{ t('audit.exportDetail', { report: reportName(row.changes.report), format: (row.changes.format ?? '').toUpperCase() + (row.changes.print ? ` (${t('audit.printed')})` : ''), rows: n(row.changes.rows ?? 0) }) }}
                            </p>
                            <p v-if="row.event === 'pruned' && row.changes?.messages" class="text-xs text-muted" data-audit-pruned>
                                {{ t('audit.prunedDetail', { messages: n(row.changes.messages), days: n(row.changes.days ?? 0) }) }}
                            </p>
                            <p v-if="row.event === 'resent' && row.changes?.address" class="break-words text-xs text-muted" data-audit-resent>
                                {{ t('audit.resentDetail', { address: row.changes.address }) }}
                            </p>
                            <p v-if="row.event === 'test_sent' && row.changes?.address" class="break-words text-xs text-muted" data-audit-test-sent>
                                {{ t('audit.testSentDetail', { address: row.changes.address }) }}
                            </p>
                            <p v-if="row.changes?.reason" class="text-xs text-muted" data-audit-reason>
                                <template v-if="row.changes.moment && te(`audit.moments.${row.changes.moment}`)">{{ t(`audit.moments.${row.changes.moment}`) }} · </template>{{ t('audit.reason', { reason: row.changes.reason }) }}
                            </p>
                        </div>
                        <time class="text-xs whitespace-nowrap text-muted">{{ formatDateTime(row.created_at) }}</time>
                    </li>
                </ul>
                <UiEmpty v-if="!activities.loading.value && !activities.rows.value.length" :title="t('common.noResults')" />
                <ListPagination :list="activities" />
            </TabsContent>

            <TabsContent value="logins" class="rounded-xl border border-line bg-surface">
                <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-3 lg:grid-cols-6">
                    <UiSelect v-model="logins.filters.event" :label="t('audit.event')" :placeholder="t('common.all')" :options="loginEvents.map((v) => ({ value: v, label: t(`audit.events.${v}`) }))" />
                    <UiSelect v-model="logins.filters.channel" :label="t('audit.channel')" :placeholder="t('common.all')" :options="loginChannels" data-login-channel />
                    <UiInput v-model="logins.filters.identifier" :label="t('audit.identifier')" />
                    <UiInput v-model="logins.filters.ip_address" :label="t('audit.ip')" />
                    <UiInput v-model="logins.filters.date_from" type="date" :label="t('common.from')" />
                    <UiInput v-model="logins.filters.date_to" type="date" :min="logins.filters.date_from || undefined" :error="logins.filterError('date_to')" :label="t('common.to')" />
                    <!-- The sign-ins list keeps its criteria under login_*: the report's link names them as the report does -->
                    <div v-if="auth.can('reports.view')" class="flex items-end sm:col-span-3 sm:justify-end lg:col-span-6">
                        <ReportDownloads report-key="audit_logins" variant="menu" :params="logins.queryParams" :total="logins.meta.value.total" :link-query="loginReportQuery" />
                    </div>
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
                                <td class="px-5 py-3 text-fg" data-login-who>
                                    <template v-if="row.cardholder">{{ row.cardholder.name }} <span class="text-muted">({{ t('audit.cardholderSelf') }}<template v-if="row.cardholder.employee_no"> · <span class="whitespace-nowrap">{{ row.cardholder.employee_no }}</span></template>)</span></template>
                                    <template v-else>{{ row.user?.name ?? row.identifier ?? '—' }}</template>
                                    <span v-if="row.channel?.key && row.channel.key !== 'staff'" class="block text-xs text-muted" data-login-channel-name>{{ row.channel.name }}</span>
                                </td>
                                <td class="px-5 py-3 font-mono text-xs text-muted">{{ row.ip_address }}</td>
                                <td class="px-5 py-3 text-xs text-muted">{{ browser(row.user_agent ?? '') }}</td>
                                <td class="px-5 py-3 text-xs whitespace-nowrap text-muted">{{ formatDateTime(row.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <UiEmpty v-if="!logins.loading.value && !logins.rows.value.length" :title="t('common.noResults')" />
                <ListPagination :list="logins" />
            </TabsContent>
        </TabsRoot>
    </div>
</template>
