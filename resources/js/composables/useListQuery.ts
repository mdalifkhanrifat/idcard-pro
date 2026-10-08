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
export function useListQuery<T>(endpoint: string | (() => string), defaults: { filters: Record<string, string>; sort?: string; perPage?: number }) {
    const route = useRoute();
    const router = useRouter();
    const ui = useUiStore();

    const filters = reactive<Record<string, string>>({ ...defaults.filters });
    for (const key of Object.keys(defaults.filters)) {
        const value = route.query[`f_${key}`];
        if (typeof value === 'string') {
            filters[key] = value;
        }
    }

    const sort = ref<string>((route.query.sort as string) ?? defaults.sort ?? '');
    const page = ref<number>(Number(route.query.page ?? 1));
    const perPage = ref<number>(Number(route.query.per_page ?? defaults.perPage ?? 15));
    const rows = ref([]) as Ref<T[]>;
    const meta = ref<PageMeta>({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
    const loading = ref(false);

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
        const params = { ...queryParams(), page: page.value, per_page: perPage.value };

        try {
            const { data } = await http.get(typeof endpoint === 'function' ? endpoint() : endpoint, { params });
            rows.value = data.data;
            meta.value = data.meta ?? data;
        } catch (error) {
            ui.notify(toApiError(error).message, 'error');
        } finally {
            loading.value = false;
        }

        // Only the list's own keys change; others in the URL (e.g. a tab or a redirect) are kept
        const query: Record<string, string | null | (string | null)[]> = Object.fromEntries(
            Object.entries(route.query).filter(([key]) => !key.startsWith('f_') && key !== 'page' && key !== 'sort'),
        );
        for (const [key, value] of Object.entries(filters)) {
            if (value) {
                query[`f_${key}`] = value;
            }
        }
        if (page.value > 1) {
            query.page = String(page.value);
        }
        if (sort.value && sort.value !== defaults.sort) {
            query.sort = sort.value;
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
                const value = route.query[`f_${key}`];
                filters[key] = typeof value === 'string' ? value : '';
            }
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

    return { filters, sort, page, perPage, rows, meta, loading, load, queryParams, resetFilters, addFilters, toggleSort };
}
