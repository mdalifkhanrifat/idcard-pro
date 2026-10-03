import axios, { AxiosError } from 'axios';

/**
 * API client. Uses the Laravel session cookie (Sanctum SPA auth) with CSRF protection.
 */
export const http = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

/** Organization a platform admin is currently acting in (sent as X-Tenant). */
let actingTenant: string | null = null;

export function setActingTenant(slug: string | null): void {
    actingTenant = slug;
}

http.interceptors.request.use((config) => {
    if (actingTenant) {
        config.headers.set('X-Tenant', actingTenant);
    }
    config.headers.set('Accept-Language', document.documentElement.lang || 'en');

    return config;
});

let unauthorizedHandler: (() => void) | null = null;

export function onUnauthorized(handler: () => void): void {
    unauthorizedHandler = handler;
}

http.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
        if (error.response?.status === 401 && unauthorizedHandler) {
            unauthorizedHandler();
        }

        return Promise.reject(error);
    },
);

export async function ensureCsrfCookie(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export interface ApiError {
    message: string;
    errors: Record<string, string[]>;
    status: number;
}

export function toApiError(error: unknown): ApiError {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;

        return {
            message: data?.message ?? error.message,
            errors: data?.errors ?? {},
            status: error.response?.status ?? 0,
        };
    }

    return { message: String(error), errors: {}, status: 0 };
}
