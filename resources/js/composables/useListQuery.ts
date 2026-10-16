import { reactive, ref, watch, type Ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { watchDebounced } from '@vueuse/core';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';

export interface PageMeta {
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

/**
 * Server-side list state (filters, sort, page) mirrored in the URL so filtered views can be bookmarked and shared.
 * Filters are sent as `filter[key]=value`, compatible with spatie/laravel-query-builder.
 */
export function useListQuery<T, M extends PageMeta = PageMeta>(
    endpoint: string | (() => string),
    defaults: {
        filters: Record<string, string>;
        sort?: string;
        perPage?: number;
        extraParams?: () => Record<string, unknown>;
        /** Put before the list's keys in the URL, so two lists on one page (e.g. the audit log's tabs) keep their own. */
        queryPrefix?: string;
    },
) {
    const route = useRoute();
    const router = useRouter();
    const ui = useUiStore();
    // The list's own keys in the URL: <prefix>f_<filter>, <prefix>page, <prefix>sort, <prefix>per_page
    const prefix = defaults.queryPrefix ?? '';
    const urlKey = (name: string) => `${prefix}${name}`;
    const isOwnUrlKey = (key: string) => key.startsWith(urlKey('f_')) || [urlKey('page'), urlKey('sort'), urlKey('per_page')].includes(key);

    const filters = reactive<Record<string, string>>({ ...defaults.filters });
    for (const key of Object.keys(defaults.filters)) {
        const value = route.query[urlKey(`f_${key}`)];
        if (typeof value === 'string') {
            filters[key] = value;
        }
    }

    const sort = ref<string>((route.query[urlKey('sort')] as string) ?? defaults.sort ?? '');
    const page = ref<number>(positive(route.query[urlKey('page')]) ?? 1);
    // Rows per page: the link's, else this browser's choice for this list, else the list's own
    const defaultPerPage = defaults.perPage ?? 15;
    // One choice per list (two lists may share a page, e.g. the audit log's tabs)
    const perPageKey = `list-per-page:${typeof endpoint === 'string' ? endpoint : route.path}`;
    const perPage = ref<number>(positive(route.query[urlKey('per_page')]) ?? positive(readStored(perPageKey)) ?? defaultPerPage);
    const rows = ref([]) as Ref<T[]>;
    const meta = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null } as PageMeta as M) as Ref<M>;
    const loading = ref(false);
    /** The last load failed for another reason than its criteria (e.g. the server or the network): offer a retry. */
    const failed = ref(false);
    /** The server's validation messages for the last load, by field (e.g. "filter.printed_to"). */
    const errors = ref<Record<string, string[]>>({});

    /** The list's filters and sort as request parameters (no page), e.g. to act on every row the filter finds. */
    function queryParams(): Record<string, string> {
        const params: Record<string, string> = {};
        if (sort.value) {
            params.sort = sort.value;
        }
        for (const [key, value] of Object.entries(filters)) {
            if (value !== '' && value !== null) {
                // "custom.class" is sent as filter[custom][class]
                params[`filter[${key.split('.').join('][')}]`] = value;
            }
        }

        return params;
    }

    async function load(): Promise<void> {
        loading.value = true;
        // Extra parameters (e.g. a report's chosen columns) shape the rows, not which rows: not in the URL
        const params = { ...queryParams(), ...defaults.extraParams?.(), page: page.value, per_page: perPage.value };

        try {
            const { data } = await http.get(typeof endpoint === 'function' ? endpoint() : endpoint, { params });
            rows.value = data.data;
            meta.value = data.meta ?? data;
            errors.value = {};
            failed.value = false;
        } catch (error) {
            const apiError = toApiError(error);
            errors.value = apiError.errors;
            failed.value = apiError.status !== 422;
            if (apiError.status === 422) {
                // The rows shown no longer match the criteria
                rows.value = [];
                meta.value = { ...meta.value, current_page: 1, last_page: 1, total: 0, from: null, to: null };
            }
            ui.notify(apiError.message, 'error');
        } finally {
            loading.value = false;
        }

        // Only the list's own keys change; others in the URL (e.g. a tab or a redirect) are kept
        const query: Record<string, string | null | (string | null)[]> = Object.fromEntries(
            Object.entries(route.query).filter(([key]) => !isOwnUrlKey(key)),
        );
        for (const [key, value] of Object.entries(filters)) {
            if (value) {
                query[urlKey(`f_${key}`)] = value;
            }
        }
        if (page.value > 1) {
            query[urlKey('page')] = String(page.value);
        }
        if (sort.value && sort.value !== defaults.sort) {
            query[urlKey('sort')] = sort.value;
        }
        if (perPage.value !== defaultPerPage) {
            query[urlKey('per_page')] = String(perPage.value);
        }
        router.replace({ query });
    }

    function resetFilters(): void {
        for (const key of Object.keys(filters)) {
            filters[key] = defaults.filters[key] ?? '';
        }
    }

    /** Register filters discovered after mount (e.g. custom fields), restoring their value from the URL. */
    function addFilters(keys: string[]): void {
        for (const key of keys) {
            if (!(key in filters)) {
                const value = route.query[urlKey(`f_${key}`)];
                filters[key] = typeof value === 'string' ? value : '';
            }
        }
    }

    /** Show this many rows per page from the first page, remembered in this browser for this list. */
    function setPerPage(size: number): void {
        page.value = 1;
        perPage.value = size;
        try {
            if (size === defaultPerPage) {
                localStorage.removeItem(perPageKey);
            } else {
                localStorage.setItem(perPageKey, String(size));
            }
        } catch {
            // Storage blocked (e.g. private mode): the choice lasts for this page only
        }
    }

    function toggleSort(field: string): void {
        sort.value = sort.value === field ? `-${field}` : field;
    }

    // Only filters with a value count, so registering new empty filters does not reset the page.
    watchDebounced(
        () => JSON.stringify(Object.entries(filters).filter(([, value]) => value !== '' && value !== null).sort()),
        () => {
            page.value = 1;
            load();
        },
        { debounce: 300 },
    );
    watch([page, sort, perPage], load);

    /** The server's message about one filter, if the last load refused it. */
    function filterError(key: string): string | undefined {
        return errors.value[`filter.${key}`]?.[0];
    }

    return { filters, sort, page, perPage, rows, meta, loading, failed, errors, load, queryParams, resetFilters, addFilters, filterError, setPerPage, toggleSort };
}

/** A whole number above zero, else null. */
function positive(value: unknown): number | null {
    const number = Number(value);

    return Number.isInteger(number) && number > 0 ? number : null;
}

function readStored(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}
