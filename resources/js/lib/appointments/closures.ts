import { http } from '@/lib/http';

/** Days appointments are not taken (GET /appointment-closures): the organization's days, both whole. */
export interface AppointmentClosure {
    id: string;
    /** null: every place. */
    location: { id: string; name: string; name_local: string | null } | null;
    date_from: string;
    date_to: string;
    /** Comes back every year on the same days, from its first year on. */
    yearly: boolean;
    reason: string | null;
    reason_local: string | null;
}

export interface ClosureInput {
    /** A place's id; null: every place. */
    location?: string | null;
    date_from?: string;
    date_to?: string;
    yearly?: boolean;
    reason?: string | null;
    reason_local?: string | null;
}

/** A closure as the dialog edits it: an empty last day is the first (one day). */
export interface ClosureForm {
    location: string;
    date_from: string;
    date_to: string;
    yearly: boolean;
    reason: string;
    reason_local: string;
}

export const blankClosure = (): ClosureForm => ({ location: '', date_from: '', date_to: '', yearly: false, reason: '', reason_local: '' });

export function closureForm(closure: AppointmentClosure): ClosureForm {
    return {
        location: closure.location?.id ?? '',
        date_from: closure.date_from,
        // One day shows an empty last day
        date_to: closure.date_to === closure.date_from ? '' : closure.date_to,
        yearly: closure.yearly,
        reason: closure.reason ?? '',
        reason_local: closure.reason_local ?? '',
    };
}

/** What to send: everything for a new closure; only what changed of one (the server keeps the rest). */
export function closureInput(form: ClosureForm, before: ClosureForm | null): ClosureInput {
    const values = (f: ClosureForm): Required<ClosureInput> => ({
        location: f.location || null,
        date_from: f.date_from,
        date_to: f.date_to || f.date_from,
        yearly: f.yearly,
        reason: f.reason.trim() || null,
        reason_local: f.reason_local.trim() || null,
    });
    const now = values(form);
    if (!before) {
        return now;
    }
    const then = values(before);

    return Object.fromEntries(Object.entries(now).filter(([key, value]) => value !== then[key as keyof ClosureInput])) as ClosureInput;
}

/** Days from the first to the last, both counted. */
export function closureDays(from: string, to: string): number {
    return Math.round((Date.UTC(...ymd(to)) - Date.UTC(...ymd(from))) / 86_400_000) + 1;
}

/** Yearly closures in the order of the year (month and day), whatever year they started. */
export function byDayOfYear(closures: AppointmentClosure[]): AppointmentClosure[] {
    return [...closures].sort((a, b) => a.date_from.slice(5).localeCompare(b.date_from.slice(5)) || a.date_from.localeCompare(b.date_from));
}

/**
 * The days in the reader's language: a day or a range ("1–2 Nov 2026"); a yearly closure without its year
 * ("21 February", "31 December – 2 January").
 */
export function closureDates(closure: Pick<AppointmentClosure, 'date_from' | 'date_to' | 'yearly'>, language: string): string {
    const format = new Intl.DateTimeFormat(language === 'bn' ? 'bn-BD' : 'en-GB', closure.yearly ? { day: 'numeric', month: 'long' } : { dateStyle: 'medium' });
    const from = new Date(...ymd(closure.date_from));
    const to = new Date(...ymd(closure.date_to));
    if (closure.date_to === closure.date_from) {
        return format.format(from);
    }

    // A range across the new year would bring its years back in: a yearly one's ends are written apart
    return closure.yearly ? `${format.format(from)} – ${format.format(to)}` : format.formatRange(from, to);
}

export async function saveClosure(id: string | null, input: ClosureInput): Promise<AppointmentClosure> {
    const { data } = id ? await http.patch<{ data: AppointmentClosure }>(`/appointment-closures/${id}`, input) : await http.post<{ data: AppointmentClosure }>('/appointment-closures', input);

    return data.data;
}

export async function deleteClosure(id: string): Promise<void> {
    await http.delete(`/appointment-closures/${id}`);
}

/** Every yearly closure (of a place and of every place, or all): few, so in one go. */
export async function fetchYearlyClosures(location: string): Promise<AppointmentClosure[]> {
    const { data } = await http.get<{ data: AppointmentClosure[] }>('/appointment-closures', {
        params: { 'filter[yearly]': 1, ...(location ? { 'filter[location]': location } : {}), per_page: 500 },
    });

    return byDayOfYear(data.data);
}

function ymd(day: string): [number, number, number] {
    const [year, month, date] = day.split('-').map(Number);

    return [year, month - 1, date];
}