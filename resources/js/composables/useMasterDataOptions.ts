import { reactive } from 'vue';
import { http } from '@/lib/http';
import type { MasterDataItem } from '@/types';

/** Items per master data type, shared by every page so each list is fetched once per session. */
const cache = reactive<Record<string, MasterDataItem[]>>({});
const pending = new Map<string, Promise<void>>();

/**
 * Dropdown options for master data lists (active items only, in their configured order).
 */
export function useMasterDataOptions() {
    function load(types: string[], force = false): Promise<void[]> {
        return Promise.all(
            types.map((type) => {
                if (!force && (cache[type] || pending.has(type))) {
                    return pending.get(type) ?? Promise.resolve();
                }
                const request = http
                    .get(`/master-data/${type}`, { params: { per_page: 'all', 'filter[is_active]': 1 } })
                    .then(({ data }) => {
                        cache[type] = data.data;
                    })
                    .finally(() => pending.delete(type));
                pending.set(type, request);

                return request;
            }),
        );
    }

    /** Options for a select; `parentId` narrows child lists (e.g. units of a department). */
    function options(type: string, parentId?: string | null): { value: string; label: string }[] {
        return (cache[type] ?? [])
            .filter((item) => parentId === undefined || (parentId ? item.parent?.id === parentId : false))
            .map((item) => ({ value: item.id, label: item.name_local && item.name_local !== item.name ? `${item.name} (${item.name_local})` : item.name }));
    }

    return { load, options };
}
