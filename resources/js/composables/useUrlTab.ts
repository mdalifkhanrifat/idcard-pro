import { ref, watch, type ComputedRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * A page's tab kept in the URL (?tab=), so a link or a reload opens it; the first tab keeps the URL plain. An
 * unknown tab is the first. `dropOnFirst` names query keys of another tab to leave out on the first (e.g. a
 * list's filters).
 */
export function useUrlTab<T extends string>(tabs: ComputedRef<T[]>, dropOnFirst: (key: string) => boolean = () => false) {
    const route = useRoute();
    const router = useRouter();
    const first = () => tabs.value[0];
    const fromUrl = (): T => (tabs.value.includes(route.query.tab as T) ? (route.query.tab as T) : first());
    const tab = ref(fromUrl()) as { value: T };

    watch(
        () => tab.value,
        (value) => router.replace({ query: { ...Object.fromEntries(Object.entries(route.query).filter(([key]) => value !== first() || !dropOnFirst(key))), tab: value === first() ? undefined : value } }),
    );
    watch(() => route.query.tab, () => (tab.value = fromUrl()));

    return tab;
}
