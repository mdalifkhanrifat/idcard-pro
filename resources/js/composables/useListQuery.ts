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

    async function load(): Promise<void> {
        loading.value = true;
        const params: Record<string, string | number> = { page: page.value, per_page: perPage.value };
        if (sort.value) {
            params.sort = sort.value;
        }
        for (const [key, value] of Object.entries(filters)) {
            if (value !== '' && value !== null) {
                params[`filter[${key}]`] = value;
            }
        }

        try {
            const { data } = await http.get(typeof endpoint === 'function' ? endpoint() : endpoint, { params });
            rows.value = data.data;
            meta.value = data.meta ?? data;
        } catch (error) {
            ui.notify(toApiError(error).message, 'error');
        } finally {
            loading.value = false;
        }

        const query: Record<string, string> = {};
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
        Object.assign(filters, defaults.filters);
    }

    function toggleSort(field: string): void {
        sort.value = sort.value === field ? `-${field}` : field;
    }

    watchDebounced(
        () => ({ ...filters }),
        () => {
            page.value = 1;
            load();
        },
        { debounce: 300, deep: true },
    );
    watch([page, sort, perPage], load);

    return { filters, sort, page, perPage, rows, meta, loading, load, resetFilters, toggleSort };
}
