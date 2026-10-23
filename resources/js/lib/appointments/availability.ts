import { http } from '@/lib/http';

/** A time a visit can start: its start and end with the organization's offset (ISO), people it takes and has left. */
export interface AvailableSlot {
    start: string;
    end: string;
    capacity: number;
    booked: number;
    available: number;
}

/** A day of a place: whether it works then (not a closed weekday nor a closure) and what can still be booked. */
export interface AvailableDay {
    date: string;
    open: boolean;
    closure: { id: string; reason: string | null; reason_local: string | null } | null;
    /** Slot booking. */
    slots?: AvailableSlot[];
    /** Day booking (served in turn). */
    capacity?: number;
    booked?: number;
    available?: number;
}

/** GET /appointment-locations/{id}/availability. */
export interface Availability {
    scheduling: 'slot' | 'day';
    /** Why nothing can be booked at all (named in the reader's language), or null. */
    unavailable: { key: string; message: string } | null;
    service: { key: string; name: string; slots: number | null };
    /** The days answered (within today and the days ahead); absent when nothing can be booked. */
    from: string | null;
    to: string | null;
    days: AvailableDay[];
}

export interface AvailabilityQuery {
    service: string;
    from: string;
    to: string;
    /** `staff` or `public` (as the public link shows it). */
    audience: string;
    /** As it will be once appointments and the place are on (setting up). */
    preview?: boolean;
    /** An appointment being moved (its public id): its own place is shown free. */
    except?: string | null;
}

export async function fetchAvailability(placeId: string, query: AvailabilityQuery): Promise<Availability> {
    const { data } = await http.get<{ data: Availability }>(`/appointment-locations/${placeId}/availability`, { params: { ...query, preview: query.preview ? 1 : undefined, except: query.except || undefined } });

    return data.data;
}

/** A day (Y-m-d) some days after another, by the calendar (no time zone involved). */
export function addDays(day: string, days: number): string {
    const [year, month, date] = day.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, date + days)).toISOString().slice(0, 10);
}

/** The organization's today (Y-m-d) in its own time zone, whatever the browser's. */
export function todayIn(timeZone: string | undefined, now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone }).format(now);
}

/**
 * A slot's start as the organization's wall clock reads it ("09:30"): taken from the ISO text as it is, never
 * moved into the browser's time zone.
 */
export const wallClock = (iso: string): string => iso.slice(11, 16);
