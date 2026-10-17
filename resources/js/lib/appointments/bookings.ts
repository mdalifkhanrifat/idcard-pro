import { wallClock } from '@/lib/appointments/availability';

/** A name from the server in the reader's language, with its key (statuses, sources, kinds of visit). */
export interface Named {
    key: string;
    name: string;
}

/** An appointment (GET /appointments, /appointments/{id}); times with the organization's offset. */
export interface Appointment {
    id: string;
    number: string;
    status: Named;
    source: Named;
    location?: { id: string; name: string; name_local: string | null };
    service: Named;
    cardholder?: { id: string; name: string; name_local: string | null; employee_no: string | null };
    family_member?: { id: string; name: string; relation: string } | null;
    /** The organization's day (Y-m-d). */
    date: string;
    /** Null when a day is booked (served in turn). */
    starts_at: string | null;
    ends_at: string | null;
    note: string | null;
    cancel_reason: string | null;
    booked_by?: { id: string; name: string } | null;
    status_changed_at: string | null;
    status_changed_by?: { id: string; name: string } | null;
    created_at: string;
    updated_at: string;
}

/** What the list can be filtered by (its `meta`), named by the server. */
export interface AppointmentListOptions {
    statuses: Named[];
    sources: Named[];
    services: Named[];
}

/** Which days the list shows: one day, from the organization's today on, or every day. */
export type AppointmentScope = 'day' | 'upcoming' | 'all';

/** The list's date filters as the scope reads them. */
export function scopeOf(filters: { from: string; to: string; past: string }): AppointmentScope {
    if (filters.from !== '' && filters.from === filters.to) {
        return 'day';
    }

    return filters.past === '1' ? 'all' : 'upcoming';
}

/**
 * The date filters for a scope — each with a value, so the link (and a reload) keeps it: a day from and to
 * itself, "upcoming" from `today` on, "all" every day (past=1).
 */
export function filtersFor(scope: AppointmentScope, day: string, today: string): { from: string; to: string; past: string } {
    if (scope === 'day') {
        return { from: day, to: day, past: '' };
    }

    return scope === 'all' ? { from: '', to: '', past: '1' } : { from: today, to: '', past: '' };
}

/** Whether a link names which days to show (else the page opens on today). */
export function namesDays(query: Record<string, unknown>, prefix = ''): boolean {
    return ['f_from', 'f_to', 'f_past'].some((key) => typeof query[prefix + key] === 'string' && query[prefix + key] !== '');
}

/** A status's colour: waiting to act on, ahead, here, finished well, missed, called off. */
export function statusTone(status: string): 'neutral' | 'success' | 'danger' | 'warning' | 'brand' {
    return ({ pending: 'warning', confirmed: 'brand', checked_in: 'success', done: 'neutral', no_show: 'danger', cancelled: 'neutral' } as const)[status as 'pending'] ?? 'neutral';
}

/** Its time on the organization's clock ("09:30–10:00"), or null when a day is booked. */
export function timeRange(appointment: Pick<Appointment, 'starts_at' | 'ends_at'>): string | null {
    if (!appointment.starts_at) {
        return null;
    }

    return appointment.ends_at ? `${wallClock(appointment.starts_at)}–${wallClock(appointment.ends_at)}` : wallClock(appointment.starts_at);
}
