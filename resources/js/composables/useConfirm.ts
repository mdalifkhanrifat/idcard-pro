import { ref } from 'vue';

interface ConfirmRequest {
    title: string;
    message?: string;
    confirmLabel?: string;
    danger?: boolean;
    resolve: (value: boolean) => void;
}

const current = ref<ConfirmRequest | null>(null);

/**
 * Promise-based confirmation dialog: `if (await confirm({ title })) { ... }`.
 */
export function useConfirm() {
    function confirm(options: Omit<ConfirmRequest, 'resolve'>): Promise<boolean> {
        return new Promise((resolve) => {
            current.value = { ...options, resolve };
        });
    }

    function settle(value: boolean): void {
        current.value?.resolve(value);
        current.value = null;
    }

    return { current, confirm, settle };
}
