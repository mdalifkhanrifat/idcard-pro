import { computed, type WritableComputedRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * Which record a page has open in a dialog, kept in the URL (?open=<id>), so a reload or a link opens it again;
 * null when none (the key then leaves the URL). The rest of the query stays as it is.
 */
export function useUrlOpen(key = 'open'): WritableComputedRef<string | null> {
    const route = useRoute();
    const router = useRouter();

    return computed({
        get: () => {
            const value = route.query[key];

            return typeof value === 'string' && value !== '' ? value : null;
        },
        set: (value) => {
            router.replace({ query: { ...route.query, [key]: value ?? undefined } });
        },
    });
}
