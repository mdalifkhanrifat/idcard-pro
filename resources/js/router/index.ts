import { createRouter, createWebHistory, type RouteComponent, type RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

declare module 'vue-router' {
    interface RouteMeta {
        guest?: boolean;
        permission?: string | string[];
        platform?: boolean;
        title?: string;
    }
}

const AuthLayout = () => import('@/layouts/AuthLayout.vue');

/**
 * Each guest page gets its own top-level path so none of them competes with the
 * application layout for "/" (which previously rendered an empty sign-in layout).
 */
function guestRoute(path: string, name: string, title: string, page: () => Promise<RouteComponent>): RouteRecordRaw {
    return {
        path,
        component: AuthLayout,
        meta: { guest: true },
        children: [{ path: '', name, component: page, meta: { guest: true, title } }],
    };
}

const routes: RouteRecordRaw[] = [
    guestRoute('/login', 'login', 'auth.signIn', () => import('@/pages/auth/LoginPage.vue')),
    guestRoute('/forgot-password', 'forgot-password', 'auth.forgotTitle', () => import('@/pages/auth/ForgotPasswordPage.vue')),
    guestRoute('/reset-password', 'reset-password', 'auth.resetTitle', () => import('@/pages/auth/ResetPasswordPage.vue')),
    {
        path: '/',
        component: () => import('@/layouts/AppLayout.vue'),
        children: [
            { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { title: 'nav.dashboard' } },
            { path: 'users', name: 'users', component: () => import('@/pages/users/UsersPage.vue'), meta: { permission: 'users.view', title: 'nav.users' } },
            { path: 'roles', name: 'roles', component: () => import('@/pages/roles/RolesPage.vue'), meta: { permission: 'roles.view', title: 'nav.roles' } },
            { path: 'cardholders/new', name: 'cardholders.create', component: () => import('@/pages/cardholders/CardholderFormPage.vue'), meta: { permission: 'cardholders.create', title: 'cardholders.new' } },
            { path: 'cardholders/:id/edit', name: 'cardholders.edit', component: () => import('@/pages/cardholders/CardholderFormPage.vue'), meta: { permission: 'cardholders.update', title: 'cardholders.editTitle' } },
            { path: 'cardholders/:id', name: 'cardholders.show', component: () => import('@/pages/cardholders/CardholderShowPage.vue'), meta: { permission: 'cardholders.view', title: 'nav.cardholders' } },
            { path: 'cardholders', name: 'cardholders', component: () => import('@/pages/cardholders/CardholdersPage.vue'), meta: { permission: 'cardholders.view', title: 'nav.cardholders' } },
            { path: 'master-data/:type?', name: 'master-data', component: () => import('@/pages/master-data/MasterDataPage.vue'), meta: { permission: 'master_data.view', title: 'nav.masterData' } },
            { path: 'custom-fields', name: 'custom-fields', component: () => import('@/pages/custom-fields/CustomFieldsPage.vue'), meta: { permission: 'settings.view', title: 'nav.customFields' } },
            { path: 'audit', name: 'audit', component: () => import('@/pages/audit/AuditPage.vue'), meta: { permission: 'audit.view', title: 'nav.audit' } },
            { path: 'settings', name: 'settings', component: () => import('@/pages/settings/OrganizationPage.vue'), meta: { permission: 'settings.view', title: 'nav.settings' } },
            { path: 'account', name: 'account', component: () => import('@/pages/account/AccountPage.vue'), meta: { title: 'nav.account' } },
            { path: 'platform/tenants', name: 'platform.tenants', component: () => import('@/pages/platform/TenantsPage.vue'), meta: { platform: true, title: 'nav.tenants' } },
        ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (!auth.loaded) {
        await auth.fetchSession();
    }

    if (to.meta.guest) {
        return auth.isAuthenticated ? { name: 'dashboard' } : true;
    }
    if (!auth.isAuthenticated) {
        return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} };
    }
    if (to.meta.platform && !auth.isPlatformAdmin) {
        return { name: 'dashboard' };
    }
    if (auth.isPlatformAdmin && !auth.tenant && to.meta.permission) {
        return { name: 'platform.tenants' };
    }
    if (to.meta.permission && !auth.can(to.meta.permission)) {
        return { name: 'dashboard' };
    }

    return true;
});
