import type { RouteLocationRaw } from 'vue-router';
import type { NotificationMessage } from '@/lib/notifications/api';

/** A message's status as a badge's tone: sent green, failed red, skipped amber, waiting grey. */
export function statusTone(status: NotificationMessage['status']['key']): 'success' | 'danger' | 'warning' | 'neutral' {
    return ({ sent: 'success', failed: 'danger', skipped: 'warning', queued: 'neutral' } as const)[status] ?? 'neutral';
}

/** Where the person a message went to is seen: the cardholder's page (a family member on its family tab). */
export function cardholderLink(recipient: NotificationMessage['recipient']): RouteLocationRaw | null {
    if (recipient?.type === 'cardholder') {
        return { path: `/cardholders/${recipient.id}` };
    }
    if (recipient?.type === 'family_member' && recipient.cardholder_id) {
        return { path: `/cardholders/${recipient.cardholder_id}`, query: { tab: 'family' } };
    }

    return null;
}
