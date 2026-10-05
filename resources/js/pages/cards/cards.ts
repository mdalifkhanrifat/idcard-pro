import { ref } from 'vue';
import { http } from '@/lib/http';
import type { IssuedCard } from '@/types';

export interface CardReasonOption {
    key: string;
    /** A note is required. */
    note: boolean;
    /** A proof document may be attached. */
    document: boolean;
}

const reasons = ref<CardReasonOption[]>([]);
let loading: Promise<void> | null = null;

/**
 * Card reasons from the server (config/cards.php), loaded once per page, so a reason added there
 * appears in every form without a frontend change.
 */
export function useCardReasons() {
    loading ??= http.get('/cards/options').then(
        ({ data }) => {
            reasons.value = data.data.reasons;
        },
        () => {
            loading = null;
        },
    );

    return {
        reasons,
        ready: loading,
        needs: (key: string): CardReasonOption => reasons.value.find((reason) => reason.key === key) ?? { key, note: false, document: false },
    };
}

/** Status shown to people: an active card past its expiry shows as expired. */
export function displayStatus(card: Pick<IssuedCard, 'status' | 'is_expired'>): 'active' | 'replaced' | 'revoked' | 'expired' {
    return card.status === 'active' && card.is_expired ? 'expired' : card.status;
}

export function statusTone(status: ReturnType<typeof displayStatus>): 'success' | 'neutral' | 'danger' | 'warning' {
    return ({ active: 'success', replaced: 'neutral', revoked: 'danger', expired: 'warning' } as const)[status];
}
