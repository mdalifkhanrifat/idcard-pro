import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { ensureCsrfCookie, http, setActingTenant } from '@/lib/http';
import { applyBrand } from '@/lib/brand';
import { setLocale } from '@/lib/i18n';
import type { Session } from '@/types';

export const useAuthStore = defineStore('auth', () => {
    const session = ref<Session | null>(null);
    const loaded = ref(false);

    const user = computed(() => session.value?.user ?? null);
    const tenant = computed(() => session.value?.tenant ?? null);
    const isAuthenticated = computed(() => session.value !== null);
    const isPlatformAdmin = computed(() => session.value?.user.is_platform_admin ?? false);

    function can(permission: string | string[] | undefined): boolean {
        if (!permission) {
            return true;
        }
        const perms = session.value?.permissions ?? [];
        if (perms.includes('*')) {
            return true;
        }

        return (Array.isArray(permission) ? permission : [permission]).some((p) => perms.includes(p));
    }

    async function fetchSession(): Promise<void> {
        try {
            const { data } = await http.get<{ data: Session }>('/auth/me');
            session.value = data.data;
            applyBrand(data.data.tenant?.primary_color);
            if (data.data.user.locale) {
                setLocale(data.data.user.locale);
            }
        } catch {
            session.value = null;
        } finally {
            loaded.value = true;
        }
    }

    async function login(payload: { organization?: string; login: string; password: string; remember: boolean }): Promise<{ twoFactor: boolean }> {
        await ensureCsrfCookie();
        const { data } = await http.post<{ two_factor: boolean }>('/auth/login', payload);
        if (!data.two_factor) {
            await fetchSession();
        }

        return { twoFactor: data.two_factor };
    }

    async function completeTwoFactor(payload: { code?: string; recovery_code?: string }): Promise<void> {
        await http.post('/auth/two-factor-challenge', payload);
        await fetchSession();
    }

    async function logout(): Promise<void> {
        try {
            await http.post('/auth/logout');
        } finally {
            clear();
        }
    }

    function clear(): void {
        session.value = null;
        setActingTenant(null);
        applyBrand(null);
    }

    async function actAs(slug: string | null): Promise<void> {
        setActingTenant(slug);
        await fetchSession();
    }

    return { session, loaded, user, tenant, isAuthenticated, isPlatformAdmin, can, fetchSession, login, completeTwoFactor, logout, clear, actAs };
});
