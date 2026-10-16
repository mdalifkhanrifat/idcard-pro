import { ref, watch, type ComputedRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * A page's tab kept in the URL (?tab=), so a link or a reload opens it; the first tab keeps the URL plain. An
 * unknown tab is the first. `ownerOf` names the tab a query key belongs to (e.g. a list's filters), so the key is
 * left out once another tab is chosen.
 */
export function useUrlTab<T extends string>(tabs: ComputedRef<T[]>, ownerOf: (key: string) => T | undefined = () => undefined) {
    const route = useRoute();
    const router = useRouter();
    const first = () => tabs.value[0];
    const fromUrl = (): T => (tabs.value.includes(route.query.tab as T) ? (route.query.tab as T) : first());
    const tab = ref(fromUrl()) as { value: T };

    watch(
        () => tab.value,
        (value) =>
            router.replace({
                query: { ...Object.fromEntries(Object.entries(route.query).filter(([key]) => [undefined, value].includes(ownerOf(key)))), tab: value === first() ? undefined : value },
            }),
    );
    watch(() => route.query.tab, () => (tab.value = fromUrl()));

    return tab;
}
