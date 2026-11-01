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

export interface WebhookDelivery {
    id: string;
    status: { key: string; name: string };
    response_code: number | null;
    failure: { key: string; name: string } | null;
}

export type WebhookForm = Pick<Webhook, 'name' | 'url' | 'enabled' | 'events'>;

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

export async function testWebhook(id: string): Promise<WebhookDelivery> {
    const { data } = await http.post<{ data: WebhookDelivery }>(`/webhooks/${id}/test`);

    return data.data;
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
