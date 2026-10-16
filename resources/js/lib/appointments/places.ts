import { http } from '@/lib/http';

/** Weekday (ISO key, e.g. `mon`) => opening periods ["HH:MM", "HH:MM"] from–to; "24:00" ends at midnight. */
export type WeeklyHours = Record<string, [string, string][]>;

/** A place people come to for an appointment (GET /appointment-locations). */
export interface AppointmentPlace {
    id: string;
    name: string;
    name_local: string | null;
    address: string | null;
    slot_capacity: number;
    day_capacity: number;
    /** Kinds of visit it takes; null = every kind (those added later too). */
    services: string[] | null;
    hours: WeeklyHours;
    is_active: boolean;
    sort_order: number;
}

export type PlaceInput = Partial<Omit<AppointmentPlace, 'id'>>;

export async function fetchPlaces(): Promise<AppointmentPlace[]> {
    const { data } = await http.get<{ data: AppointmentPlace[] }>('/appointment-locations');

    return data.data;
}

/** A new place, or only what changed of one (a weekday of the hours not sent keeps its periods). */
export async function savePlace(id: string | null, input: PlaceInput): Promise<AppointmentPlace> {
    const { data } = id ? await http.patch<{ data: AppointmentPlace }>(`/appointment-locations/${id}`, input) : await http.post<{ data: AppointmentPlace }>('/appointment-locations', input);

    return data.data;
}

export async function deletePlace(id: string): Promise<void> {
    await http.delete(`/appointment-locations/${id}`);
}

/**
 * Mistakes in a week of hours, by field as the server names them (WeeklyHours::mistakes): a period that does not
 * end after it starts (`hours.mon.1`), or periods of a day that overlap (`hours.mon`). Values are message keys.
 */
export function hoursMistakes(hours: WeeklyHours, weekdays: string[]): Record<string, 'period_order' | 'periods_overlap'> {
    const mistakes: Record<string, 'period_order' | 'periods_overlap'> = {};
    for (const day of weekdays) {
        const periods = hours[day] ?? [];
        periods.forEach(([from, to], index) => {
            if (from >= to) {
                mistakes[`hours.${day}.${index}`] = 'period_order';
            }
        });
        const ordered = [...periods].sort((a, b) => a[0].localeCompare(b[0]));
        for (let i = 1; i < ordered.length; i++) {
            if (ordered[i][0] < ordered[i - 1][1]) {
                mistakes[`hours.${day}`] = 'periods_overlap';
            }
        }
    }

    return mistakes;
}

/** A day's periods copied to every other open day (closed days stay closed). */
export function copyToOpenDays(hours: WeeklyHours, from: string): WeeklyHours {
    return Object.fromEntries(Object.entries(hours).map(([day, periods]) => [day, day !== from && periods.length ? hours[from].map(([a, b]) => [a, b] as [string, string]) : periods]));
}

/** A period's end as a time box shows it: midnight ("24:00") as 00:00; and back. */
export const endShown = (time: string): string => (time === '24:00' ? '00:00' : time);
export const endStored = (time: string): string => (time === '00:00' ? '24:00' : time);
