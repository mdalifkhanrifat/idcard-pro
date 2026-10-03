<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { CalendarClock, ContactRound, CreditCard, Fingerprint, LayoutTemplate, ShieldCheck, Users } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiCard from '@/components/ui/UiCard.vue';
import { http } from '@/lib/http';
import { formatRelative } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const { t } = useI18n();

const stats = ref({ users: 0, twoFactor: 0, roles: 0 });
const logins = ref<{ id: number; event: string; identifier: string; ip_address: string; created_at: string; user: { name: string } | null }[]>([]);
const coverage = computed(() => (stats.value.users ? Math.round((stats.value.twoFactor / stats.value.users) * 100) : 0));

onMounted(async () => {
    const jobs: Promise<unknown>[] = [];
    if (auth.can('users.view')) {
        jobs.push(http.get('/users', { params: { per_page: 1 } }).then(({ data }) => (stats.value.users = data.meta.total)));
        jobs.push(http.get('/users', { params: { per_page: 1, 'filter[two_factor]': 1 } }).then(({ data }) => (stats.value.twoFactor = data.meta.total)));
    }
    if (auth.can('roles.view')) {
        jobs.push(http.get('/roles').then(({ data }) => (stats.value.roles = data.data.length)));
    }
    if (auth.can('audit.view')) {
        jobs.push(http.get('/audit/logins', { params: { per_page: 6 } }).then(({ data }) => (logins.value = data.data)));
    }
    await Promise.allSettled(jobs);
});

const roadmap = [
    { icon: ContactRound, key: 'nav.cardholders', phase: 2 },
    { icon: LayoutTemplate, key: 'nav.templates', phase: 3 },
    { icon: CreditCard, key: 'nav.cards', phase: 3 },
    { icon: Fingerprint, key: 'Biometrics', phase: 4 },
    { icon: CalendarClock, key: 'nav.appointments', phase: 5 },
];

const eventTone: Record<string, 'success' | 'danger' | 'warning' | 'neutral'> = {
    succeeded: 'success', failed: 'danger', locked: 'danger', blocked: 'danger', two_factor_failed: 'warning',
};
</script>

<template>
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ t('dashboard.welcome', { name: auth.user?.name?.split(' ')[0] }) }}</h1>
        <p class="mt-1 text-sm text-muted">{{ t('dashboard.subtitle', { org: auth.tenant?.name }) }}</p>

        <div class="mt-8 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center justify-between text-sm text-muted">{{ t('dashboard.users') }}<Users class="size-4" /></div>
                <p class="mt-3 text-3xl font-semibold tabular-nums text-fg">{{ stats.users }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center justify-between text-sm text-muted">{{ t('dashboard.roles') }}<ShieldCheck class="size-4" /></div>
                <p class="mt-3 text-3xl font-semibold tabular-nums text-fg">{{ stats.roles }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center justify-between text-sm text-muted">{{ t('dashboard.twoFactor') }}<ShieldCheck class="size-4" /></div>
                <p class="mt-3 text-3xl font-semibold tabular-nums text-fg">{{ coverage }}%</p>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-2">
                    <div class="h-full rounded-full bg-brand transition-all" :style="{ width: `${coverage}%` }" />
                </div>
                <p class="mt-2 text-xs text-muted">{{ t('dashboard.twoFactorCoverage', { percent: coverage }) }}</p>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[1.4fr_1fr]">
            <UiCard v-if="auth.can('audit.view')" :title="t('dashboard.recentLogins')" :padded="false">
                <ul class="divide-y divide-line">
                    <li v-for="item in logins" :key="item.id" class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-fg">{{ item.user?.name ?? item.identifier }}</p>
                            <p class="text-xs text-muted">{{ item.ip_address }} · {{ formatRelative(item.created_at) }}</p>
                        </div>
                        <UiBadge :tone="eventTone[item.event] ?? 'neutral'">{{ t(`audit.events.${item.event}`) }}</UiBadge>
                    </li>
                    <li v-if="!logins.length" class="px-5 py-8 text-center text-sm text-muted">{{ t('common.noResults') }}</li>
                </ul>
            </UiCard>

            <UiCard :title="t('dashboard.roadmap')" :description="t('dashboard.roadmapHint')">
                <ul class="space-y-3">
                    <li v-for="item in roadmap" :key="item.key" class="flex items-center gap-3 text-sm">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-surface-2 text-muted"><component :is="item.icon" class="size-4" /></span>
                        <span class="flex-1 text-fg">{{ item.key.includes('.') ? t(item.key) : item.key }}</span>
                        <UiBadge>Phase {{ item.phase }}</UiBadge>
                    </li>
                </ul>
            </UiCard>
        </div>
    </div>
</template>
