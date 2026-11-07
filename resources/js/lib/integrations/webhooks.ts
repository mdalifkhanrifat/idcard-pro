import { http } from '@/lib/http';

/** Where an organization wants to be told, about what (6.2). */
export interface Webhook {
    id: string;
    name: string;
    url: string;
    events: string[];
    enabled: boolean;
    failure_count: number;
    disabled_at: string | null;
    secret_rotated_at: string | null;
    created_at: string;
}

/** The signing secret comes with making or changing it only and cannot be asked for again. */
export interface WebhookWithSecret extends Webhook {
    secret: string;
}

export interface WebhookEventGroup {
    key: string;
    name: string;
    /** Whether this person may send this data somewhere: they must be allowed to see it themselves. */
    allowed: boolean;
    events: { key: string; name: string }[];
}

interface Named {
    key: string;
    name: string;
}

/** One line of the log. */
export interface WebhookDeliveryLine {
    id: string;
    event: Named;
    status: Named;
    attempts: number;
    response_code: number | null;
    failure: Named | null;
    delivered_at: string | null;
    created_at: string;
    resend_of: string | null;
    resent_as: string | null;
    /** Null where it can never apply; otherwise whether it can go now, by the rule the resend itself follows. */
    actions: { resend: { allowed: boolean; reason: string | null } | null };
}

/** With what was sent, as the exact text, unless this person may not see that data. */
export interface WebhookDeliveryDetail extends WebhookDeliveryLine {
    payload: string | null;
    payload_hidden: boolean;
}

export interface WebhookLogMeta {
    statuses: Named[];
    events: Named[];
}

export type WebhookForm = Pick<Webhook, 'name' | 'url' | 'enabled' | 'events'>;

export async function fetchWebhook(id: string): Promise<Webhook> {
    const { data } = await http.get<{ data: Webhook }>(`/webhooks/${id}`);

    return data.data;
}

export async function createWebhook(form: WebhookForm): Promise<WebhookWithSecret> {
    const { data } = await http.post<{ data: WebhookWithSecret }>('/webhooks', form);

    return data.data;
}

export async function updateWebhook(id: string, changes: Partial<WebhookForm>): Promise<void> {
    await http.put(`/webhooks/${id}`, changes);
}

export async function deleteWebhook(id: string): Promise<void> {
    await http.delete(`/webhooks/${id}`);
}

export async function rotateWebhookSecret(id: string): Promise<WebhookWithSecret> {
    const { data } = await http.post<{ data: WebhookWithSecret }>(`/webhooks/${id}/secret`);

    return data.data;
}

export async function testWebhook(id: string): Promise<WebhookDeliveryLine> {
    const { data } = await http.post<{ data: WebhookDeliveryLine }>(`/webhooks/${id}/test`);

    return data.data;
}

export async function fetchDelivery(webhookId: string, id: string): Promise<WebhookDeliveryDetail> {
    const { data } = await http.get<{ data: WebhookDeliveryDetail }>(`/webhooks/${webhookId}/deliveries/${id}`);

    return data.data;
}

export async function resendDelivery(webhookId: string, id: string): Promise<WebhookDeliveryDetail> {
    const { data } = await http.post<{ data: WebhookDeliveryDetail }>(`/webhooks/${webhookId}/deliveries/${id}/resend`);

    return data.data;
}

export function deliveryTone(status: string): 'success' | 'danger' | 'neutral' {
    return status === 'delivered' ? 'success' : status === 'failed' ? 'danger' : 'neutral';
}

/** Laid out to read. Parsing here is only for showing: an empty object stays an object, and other text stays as it is. */
export function prettyPayload(payload: string | null): string {
    if (payload === null) {
        return '';
    }
    try {
        return JSON.stringify(JSON.parse(payload), null, 2);
    } catch {
        return payload;
    }
}

/**
 * Seconds from the first try to the last, for this many tries in all: fewer tries keep the first of the server's
 * waits (as the server itself does), so this is what the organization's choice means.
 */
export function lastTryAfter(attempts: number, backoff: number[]): number {
    return backoff.slice(0, Math.max(0, attempts - 1)).reduce((sum, wait) => sum + wait, 0);
}

/** Off by failing, not by a person's choice: only that leaves a moment, and it goes when turned on again. */
export function turnedItselfOff(webhook: Pick<Webhook, 'enabled' | 'disabled_at'>): boolean {
    return !webhook.enabled && webhook.disabled_at !== null;
}

export function groupState(selected: string[], group: WebhookEventGroup): 'all' | 'some' | 'none' {
    const chosen = group.events.filter((event) => selected.includes(event.key)).length;

    return chosen === 0 ? 'none' : chosen === group.events.length ? 'all' : 'some';
}

/** The whole group on or off, whatever else is chosen left as it is. */
export function withGroup(selected: string[], group: WebhookEventGroup, on: boolean): string[] {
    const keys = group.events.map((event) => event.key);
    const others = selected.filter((key) => !keys.includes(key));

    return on ? [...others, ...keys] : others;
}

/** An event no longer offered cannot be saved again: it stops being chosen with the next save. */
export function knownEvents(selected: string[], groups: WebhookEventGroup[]): string[] {
    const known = groups.flatMap((group) => group.events.map((event) => event.key));

    return selected.filter((key) => known.includes(key));
}
