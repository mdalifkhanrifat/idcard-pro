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
}

/** tenants.settings.notifications, always complete (the server fills in defaults). */
export interface NotificationSettings {
    enabled: boolean;
    /** Channels messages go on, in order. */
    channels: string[];
    /** Languages a message is written in, in order (at least one); several make one message. */
    languages: string[];
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
}

/** What this installation can send and about what (the registry): the page is drawn from this alone. */
export interface NotificationOptions {
    channels: string[];
    languages: string[];
    labels: { channels: Record<string, string>; languages: Record<string, string> };
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
