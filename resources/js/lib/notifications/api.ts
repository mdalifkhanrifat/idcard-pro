import { http } from '@/lib/http';

/** A part of a message an organization may write: a mail's subject and body, an SMS. */
export type NotificationPart = 'subject' | 'body' | 'sms';

/** The organization's own texts in one language; null = the event's default text. */
export type NotificationTexts = Record<NotificationPart, string | null>;

/** One event as the organization chose it. */
export interface NotificationEventSettings {
    enabled: boolean;
    /** Channels it goes on (within the organization's channels). */
    channels: string[];
    /** Language => its own texts. */
    templates: Record<string, NotificationTexts>;
    /** Its own settings, for an event that has them (EventOptionDescription.key => value). */
    options?: Record<string, EventOptionValue>;
}

/** A value of an event's own setting: one choice, or a list of whole numbers (largest first). */
export type EventOptionValue = string | number[];

/** An event's own setting as the server describes it (e.g. when "card ready" goes, days before a card's last day). */
export interface EventOptionDescription {
    key: string;
    kind: 'choice' | 'numbers';
    label: string;
    hint: string;
    default: EventOptionValue;
    /** For a choice. */
    choices?: { key: string; name: string }[];
    /** For numbers: each from min to max, at most max_items of them, each followed by unit (e.g. "days before"). */
    min?: number;
    max?: number;
    max_items?: number;
    unit?: string;
}

/** tenants.settings.notifications, always complete (the server fills in defaults). */
export interface NotificationSettings {
    enabled: boolean;
    /** Channels messages go on, in order. */
    channels: string[];
    /** Languages a message is written in, in order (at least one); several make one message. */
    languages: string[];
    /** An SMS in all the languages (`all`) or only the first (`first`): shorter, fewer parts. */
    sms_languages: string;
    /** Whether messages older than log_days are deleted daily (off by default). */
    prune_log: boolean;
    log_days: number;
    events: Record<string, NotificationEventSettings>;
}

/** An event of the server's registry, named and described in the reader's language. */
export interface NotificationEventOption {
    name: string;
    description: string;
    placeholders: { key: string; label: string }[];
    default_channels: string[];
    /** Language => the default texts. */
    default_templates: Record<string, Record<NotificationPart, string>>;
    /** Its own settings (none: []). */
    options: EventOptionDescription[];
}

/** What this installation can send and about what (the registry): the page is drawn from this alone. */
export interface NotificationOptions {
    channels: string[];
    /** Channel => the kind of address it goes to. */
    channel_addresses: Record<string, 'email' | 'phone' | string>;
    languages: string[];
    /** Choices for NotificationSettings.sms_languages. */
    sms_languages: string[];
    labels: { channels: Record<string, string>; languages: Record<string, string>; sms_languages: Record<string, string> };
    log_days: { min: number; max: number };
    max_length: Record<NotificationPart, number>;
    /** Between the languages of one message. */
    separators: Record<NotificationPart, string>;
    events: Record<string, NotificationEventOption>;
}

/** Event => language => placeholder => value, for a made-up occurrence (previews). */
export type NotificationSamples = Record<string, Record<string, Record<string, string>>>;

export interface NotificationSettingsResponse {
    notifications: NotificationSettings;
    options: NotificationOptions;
    samples: NotificationSamples;
}

/** GET /notifications/settings (notifications.view or settings.update). */
export async function fetchNotificationSettings(): Promise<NotificationSettingsResponse> {
    const { data } = await http.get<{ data: NotificationSettingsResponse }>('/notifications/settings');

    return data.data;
}

/** PUT /notifications/settings (settings.update): only what is sent changes; the saved settings come back. */
export async function saveNotificationSettings(notifications: Partial<NotificationSettings>): Promise<NotificationSettingsResponse> {
    const { data } = await http.put<{ data: NotificationSettingsResponse }>('/notifications/settings', { notifications });

    return data.data;
}

/** Names of keys from the registry's labels, the key itself until it has a name. */
export function labelsOf(keys: readonly string[], labels: Record<string, string>): string[] {
    return keys.map((key) => labels[key] ?? key);
}

/** A name the server gives with its key (an event, channel, status or reason in the reader's language). */
export interface Named {
    key: string;
    name: string;
}

/** A logged message (NotificationMessageResource): to whom, about what, the words as sent, and how it went. */
export interface NotificationMessage {
    id: string;
    event: Named;
    channel: Named;
    address: string | null;
    /** Null once the person's record is gone (the address stays). */
    recipient: { type: 'cardholder' | 'family_member' | 'user'; id: string; name: string; cardholder_id?: string } | null;
    card: { id: string; number: string } | null;
    languages: string[];
    subject: string | null;
    body: string;
    status: { key: 'queued' | 'sent' | 'failed' | 'skipped'; name: string };
    reason: Named | null;
    error: string | null;
    attempts: number;
    driver: string | null;
    provider_id: string | null;
    /** Who sent it by hand; null = the system on an event. */
    sent_by: { id: string; name: string } | null;
    /** The message this one sends again, and the one that sent this one again. */
    resend_of: string | null;
    resent_as: string | null;
    created_at: string;
    sent_at: string | null;
    failed_at: string | null;
}

/** What the log can be filtered by (GET /notifications/messages → meta), named by the server. */
export interface NotificationLogOptions {
    events: Named[];
    channels: Named[];
    statuses: Named[];
}

/** POST /notifications/test (notifications.send): fixed words to the address typed, whatever the settings. */
export async function sendTestMessage(channel: string, address: string): Promise<NotificationMessage> {
    const { data } = await http.post<{ data: NotificationMessage }>('/notifications/test', { channel, address });

    return data.data;
}

/** GET /notifications/messages/{id} (notifications.view). */
export async function fetchMessage(id: string): Promise<NotificationMessage> {
    const { data } = await http.get<{ data: NotificationMessage }>(`/notifications/messages/${id}`);

    return data.data;
}

/** POST /notifications/messages/{id}/resend (notifications.send): a failed or skipped message again, as a new one. */
export async function resendMessage(id: string): Promise<NotificationMessage> {
    const { data } = await http.post<{ data: NotificationMessage }>(`/notifications/messages/${id}/resend`);

    return data.data;
}
