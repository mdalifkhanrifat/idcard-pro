import type { CardholderStatus } from '@/types';

export function statusTone(status: CardholderStatus): 'success' | 'neutral' | 'warning' {
    return status === 'active' ? 'success' : status === 'retired' ? 'warning' : 'neutral';
}
