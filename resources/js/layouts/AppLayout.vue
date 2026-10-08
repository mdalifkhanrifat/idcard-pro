<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuSeparator, DropdownMenuTrigger } from 'reka-ui';
import {
    BadgeCheck, Building2, CalendarClock, Database, ChevronDown, ContactRound, CreditCard, FileBarChart, Images, LayoutDashboard, LayoutTemplate, ListPlus,
    LogOut, Menu, ScrollText, Settings, ShieldCheck, Signature, Type, UserCog, Users, X,
} from 'lucide-vue-next';
import LocaleThemeSwitch from '@/components/LocaleThemeSwitch.vue';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';

const auth = useAuthStore();
const ui = useUiStore();
const route = useRoute();
const router = useRouter();
const { t } = useI18n();

interface NavItem {
    to?: string;
    label: string;
    icon: unknown;
    permission?: string;
    soon?: boolean;
}

const sections = computed<{ title?: string; items: NavItem[] }[]>(() => {
    const result: { title?: string; items: NavItem[] }[] = [];

    if (auth.tenant) {
        result.push({ items: [{ to: '/', label: t('nav.dashboard'), icon: LayoutDashboard }] });
        if (auth.can('cardholders.view')) {
            result.push({ title: t('nav.records'), items: [{ to: '/cardholders', label: t('nav.cardholders'), icon: ContactRound }] });
        }
        const cardItems = [
            { to: '/cards', label: t('nav.cards'), icon: CreditCard, permission: 'cards.view' },
            { to: '/card-templates', label: t('nav.templates'), icon: LayoutTemplate, permission: 'templates.view' },
        ].filter((item) => auth.can(item.permission));
        if (cardItems.length) {
            result.push({ title: t('nav.cardsSection'), items: cardItems });
        }
        result.push({
            title: t('nav.comingSoon'),
            items: [
                { label: t('nav.appointments'), icon: CalendarClock, soon: true },
                { label: t('nav.reports'), icon: FileBarChart, soon: true },
            ],
        });
        const setup = [
            { to: '/master-data', label: t('nav.masterData'), icon: Database, permission: 'master_data.view' },
            { to: '/custom-fields', label: t('nav.customFields'), icon: ListPlus, permission: 'settings.view' },
            { to: '/signatories', label: t('nav.signatories'), icon: Signature, permission: 'signatories.view' },
            { to: '/image-library', label: t('nav.imageLibrary'), icon: Images, permission: 'images.view' },
            { to: '/fonts', label: t('nav.fonts'), icon: Type, permission: 'fonts.view' },
        ].filter((item) => auth.can(item.permission));
        if (setup.length) {
            result.push({ title: t('nav.setup'), items: setup });
        }
        result.push({
            title: t('nav.administration'),
            items: [
                { to: '/users', label: t('nav.users'), icon: Users, permission: 'users.view' },
                { to: '/roles', label: t('nav.roles'), icon: ShieldCheck, permission: 'roles.view' },
                { to: '/audit', label: t('nav.audit'), icon: ScrollText, permission: 'audit.view' },
                { to: '/settings', label: t('nav.settings'), icon: Settings, permission: 'settings.view' },
            ].filter((item) => auth.can(item.permission)),
        });
    }

    if (auth.isPlatformAdmin) {
        result.push({ title: t('nav.platform'), items: [{ to: '/platform/tenants', label: t('nav.tenants'), icon: Building2 }] });
    }

    return result.filter((s) => s.items.length);
});

function isActive(to?: string): boolean {
    return !!to && (to === '/' ? route.path === '/' : route.path.startsWith(to));
}

const initials = computed(() =>
    (auth.user?.name ?? '?')
        .split(' ')
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);

async function logout(): Promise<void> {
    await auth.logout();
    router.push({ name: 'login' });
}

async function exitTenant(): Promise<void> {
    await auth.actAs(null);
    router.push({ name: 'platform.tenants' });
}
</script>

<template>
    <div class="flex min-h-full">
        <!-- Sidebar -->
        <div v-if="ui.sidebarOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="ui.sidebarOpen = false" />
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-line bg-surface transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
            :class="ui.sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 items-center gap-3 border-b border-line px-5">
                <img v-if="auth.tenant?.logo_url" :src="auth.tenant.logo_url" alt="" class="size-8 rounded-md object-contain" />
                <div v-else class="flex size-8 items-center justify-center rounded-md bg-brand text-brand-contrast"><BadgeCheck class="size-4" /></div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-fg">{{ auth.tenant?.name ?? t('app.name') }}</p>
                    <p class="truncate text-xs text-muted">{{ auth.tenant ? auth.tenant.slug : t('nav.platform') }}</p>
                </div>
                <button class="text-muted lg:hidden" aria-label="Close menu" @click="ui.sidebarOpen = false"><X class="size-5" /></button>
            </div>

            <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
                <div v-for="(section, index) in sections" :key="index">
                    <p v-if="section.title" class="mb-1.5 px-2 text-[11px] font-semibold tracking-wider text-muted uppercase">{{ section.title }}</p>
                    <ul class="space-y-0.5">
                        <li v-for="item in section.items" :key="item.label">
                            <RouterLink
                                v-if="item.to"
                                :to="item.to"
                                class="flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm transition-colors"
                                :class="isActive(item.to) ? 'bg-brand/10 font-medium text-brand-ink' : 'text-muted hover:bg-surface-2 hover:text-fg'"
                                @click="ui.sidebarOpen = false"
                            >
                                <component :is="item.icon" class="size-4" />{{ item.label }}
                            </RouterLink>
                            <span v-else class="flex cursor-default items-center gap-3 rounded-lg px-2.5 py-2 text-sm text-muted/60">
                                <component :is="item.icon" class="size-4" />{{ item.label }}
                            </span>
                        </li>
                    </ul>
                </div>
            </nav>
        </aside>

        <!-- Main -->
        <div class="flex min-w-0 flex-1 flex-col">
            <div v-if="auth.isPlatformAdmin && auth.tenant" class="flex items-center justify-center gap-3 bg-warning/15 px-4 py-1.5 text-sm text-warning">
                {{ t('platform.actingAs', { org: auth.tenant.name }) }}
                <button class="font-semibold underline" @click="exitTenant">{{ t('platform.exit') }}</button>
            </div>
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface/80 px-4 backdrop-blur sm:px-6">
                <button class="rounded-lg p-2 text-muted hover:bg-surface-2 lg:hidden" aria-label="Open menu" @click="ui.sidebarOpen = true">
                    <Menu class="size-5" />
                </button>
                <div class="flex-1" />
                <LocaleThemeSwitch />
                <DropdownMenuRoot>
                    <DropdownMenuTrigger class="flex items-center gap-2 rounded-lg py-1 pr-2 pl-1 hover:bg-surface-2">
                        <span class="flex size-8 items-center justify-center rounded-full bg-brand/15 text-xs font-semibold text-brand-ink">{{ initials }}</span>
                        <span class="hidden text-left sm:block">
                            <span class="block text-sm leading-tight font-medium text-fg">{{ auth.user?.name }}</span>
                            <span class="block text-xs leading-tight text-muted">{{ auth.user?.roles?.join(', ') || (auth.isPlatformAdmin ? 'Platform' : '') }}</span>
                        </span>
                        <ChevronDown class="size-4 text-muted" />
                    </DropdownMenuTrigger>
                    <DropdownMenuPortal>
                        <DropdownMenuContent :side-offset="6" align="end" class="z-50 min-w-52 rounded-xl border border-line bg-surface p-1 shadow-xl">
                            <DropdownMenuItem class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm text-fg outline-none data-[highlighted]:bg-surface-2" @select="router.push('/account')">
                                <UserCog class="size-4" />{{ t('nav.account') }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator class="my-1 h-px bg-line" />
                            <DropdownMenuItem class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm text-danger outline-none data-[highlighted]:bg-surface-2" @select="logout">
                                <LogOut class="size-4" />{{ t('auth.logout') }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenuPortal>
                </DropdownMenuRoot>
            </header>
            <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6"><RouterView /></main>
        </div>
    </div>
</template>
