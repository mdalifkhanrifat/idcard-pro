import { addDays, wallClock } from '@/lib/appointments/availability';
import { http } from '@/lib/http';

/** A name from the server in the reader's language, with its key (statuses, sources, kinds of visit). */
export interface Named {
    key: string;
    name: string;
}

/** An appointment (GET /appointments, /appointments/{id}); times with the organization's offset. */
export interface Appointment {
    id: string;
    number: string;
    /** `open` while still to be served (waiting, confirmed, came in): a finished one is only corrected. */
    status: Named & { open?: boolean };
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
    /** What the reader may do with it now — in answers about one appointment only, not in lists (5.5c). */
    actions?: AppointmentActions;
}

/** Whether something may be done now, and if not, why (the server's own words). */
export interface Verdict {
    allowed: boolean;
    reason: string | null;
}

/** A step an appointment can take, named for what it does; `correction` when it goes back from a mistake. */
export interface AppointmentStep extends Verdict {
    status: Named;
    label: string;
    correction: boolean;
}

/**
 * What the reader may do with an appointment now (AppointmentActions on the server): null, or no step, for what
 * their role never allows — so nothing is offered that could not be.
 */
export interface AppointmentActions {
    move: Verdict | null;
    /** Changing its note only: while it is ahead, also while appointments are off. */
    note: Verdict | null;
    cancel: Verdict | null;
    steps: AppointmentStep[];
}

/** Calling an appointment off: `cancel` for those who book (POST …/cancel), else the step to "cancelled" for those who only manage (POST …/status), with its own name. */
export interface StopAction extends Verdict {
    via: 'cancel' | 'status';
    label: string | null;
}

/**
 * The buttons of an appointment's details: the steps ahead, moving it, calling it off, and going back from a
 * mistake. Once finished (not `open`), moving and calling it off are not shown as buttons that cannot be pressed —
 * only a correction could reopen it.
 */
export function layoutActions(actions: AppointmentActions | undefined, open = true): { forward: AppointmentStep[]; move: Verdict | null; stop: StopAction | null; corrections: AppointmentStep[] } {
    const steps = actions?.steps ?? [];
    const cancelling = steps.find((step) => step.status.key === 'cancelled' && !step.correction);
    let stop: StopAction | null = null;
    if (actions?.cancel) {
        stop = { via: 'cancel', label: null, ...actions.cancel };
    } else if (cancelling) {
        stop = { via: 'status', label: cancelling.label, allowed: cancelling.allowed, reason: cancelling.reason };
    }

    return {
        forward: steps.filter((step) => !step.correction && step !== cancelling),
        move: actions?.move && (actions.move.allowed || open) ? actions.move : null,
        stop: stop && (stop.allowed || open) ? stop : null,
        corrections: steps.filter((step) => step.correction),
    };
}

/** One appointment with what the reader may do with it now. */
export async function fetchAppointment(id: string): Promise<Appointment> {
    const { data } = await http.get<{ data: Appointment }>(`/appointments/${id}`);

    return data.data;
}

/** Where and when it moves to: a place, a day and a time ("HH:MM" on the organization's clock; null when a day is booked). */
export interface MoveInput {
    location: string;
    date: string;
    time: string | null;
}

/** Move one still ahead (appointments.book), by the rules of a new booking, its own place not counted; number and status stay. */
export async function moveAppointment(id: string, input: MoveInput): Promise<Appointment> {
    const { data } = await http.patch<{ data: Appointment }>(`/appointments/${id}`, input);

    return data.data;
}

/** Change only its note (appointments.book; null takes it away). */
export async function changeAppointmentNote(id: string, note: string | null): Promise<Appointment> {
    const { data } = await http.patch<{ data: Appointment }>(`/appointments/${id}`, { note });

    return data.data;
}

/** Cancel one still ahead (appointments.book), with a reason if given. */
export async function cancelAppointment(id: string, reason: string | null): Promise<Appointment> {
    const { data } = await http.post<{ data: Appointment }>(`/appointments/${id}/cancel`, { reason });

    return data.data;
}

/** Take it a step (appointments.manage); `reason` when it is turned down or cancelled. */
export async function changeAppointmentStatus(id: string, status: string, reason: string | null = null): Promise<Appointment> {
    const { data } = await http.post<{ data: Appointment }>(`/appointments/${id}/status`, { status, reason });

    return data.data;
}

/** What the list can be filtered by (its `meta`), named by the server. */
export interface AppointmentListOptions {
    statuses: Named[];
    sources: Named[];
    services: Named[];
}

/** Which days the list shows: one day, a week (seven days from one), from the organization's today on, or every day. */
export type AppointmentScope = 'day' | 'week' | 'upcoming' | 'all';

/** Days a week view shows, from its first. */
export const WEEK_DAYS = 7;

/** The list's date filters as the scope reads them. */
export function scopeOf(filters: { from: string; to: string; past: string }): AppointmentScope {
    if (filters.from !== '' && filters.from === filters.to) {
        return 'day';
    }
    if (filters.from !== '' && filters.to !== '') {
        return 'week';
    }

    return filters.past === '1' ? 'all' : 'upcoming';
}

/**
 * The date filters for a scope — each with a value, so the link (and a reload) keeps it: a day from and to
 * itself, a week from `day` for seven days, "upcoming" from `today` on, "all" every day (past=1).
 */
export function filtersFor(scope: AppointmentScope, day: string, today: string): { from: string; to: string; past: string } {
    if (scope === 'day') {
        return { from: day, to: day, past: '' };
    }
    if (scope === 'week') {
        return { from: day, to: addDays(day, WEEK_DAYS - 1), past: '' };
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

/** Someone to book for (GET /appointments/people): who they are and their family members, nothing more. */
export interface BookablePerson {
    id: string;
    name: string;
    name_local: string | null;
    employee_no: string | null;
    status: string;
    family_members: { id: string; name: string; relation: string }[];
}

/** People to book for by name, local name or employee number (two characters at least): ten at most. */
export async function findPeople(search: string): Promise<BookablePerson[]> {
    const { data } = await http.get<{ data: BookablePerson[] }>('/appointments/people', { params: { search } });

    return data.data;
}

export interface BookingInput {
    location: string;
    service: string;
    cardholder: string;
    family_member: string | null;
    date: string;
    /** "HH:MM" on the organization's clock; null when a day is booked. */
    time: string | null;
    note: string | null;
}

/** POST /appointments (appointments.book): checked again by the server, which may refuse a time taken meanwhile. */
export async function bookAppointment(input: BookingInput): Promise<Appointment> {
    const { data } = await http.post<{ data: Appointment }>('/appointments', input);

    return data.data;
}

/** The appointment with this number exactly (any day, any case), or null. */
export async function findByNumber(number: string): Promise<Appointment | null> {
    const { data } = await http.get<{ data: Appointment[] }>('/appointments', { params: { 'filter[number]': number.trim(), per_page: 10 } });

    return data.data[0] ?? null;
}

/** A day's appointments counted by status (GET /appointments/summary). */
export interface DaySummary {
    date: string;
    total: number;
    statuses: Record<string, number>;
}

/** Appointments counted by day and status under the list's criteria (`filter[from]` and `filter[to]` needed). */
export async function fetchSummary(params: Record<string, string>): Promise<{ days: DaySummary[]; statuses: Named[] }> {
    const { data } = await http.get<{ data: { days: DaySummary[] }; meta: { statuses: Named[] } }>('/appointments/summary', { params });

    return { days: data.data.days, statuses: data.meta.statuses };
}
