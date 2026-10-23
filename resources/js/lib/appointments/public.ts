import type { Availability, AvailabilityQuery } from '@/lib/appointments/availability';
import type { Named, Verdict } from '@/lib/appointments/bookings';
import { http } from '@/lib/http';

/**
 * The public booking link (5.6): /api/v1/public/<organization>/booking, no sign-in. A person proves who they are
 * (employee number, then the organization's method) and gets a session token, kept for the tab only
 * (sessionStorage) and sent as X-Booking-Session.
 */

/** What the link offers (GET …/booking): from the organization's settings, nothing about people. */
export interface PublicBookingInfo {
    organization: { name: string; logo_url: string | null; primary_color: string | null; timezone: string };
    scheduling: 'slot' | 'day';
    days_ahead: number;
    notice_hours: number;
    family_members: boolean;
    confirmation: 'auto' | 'approval';
    verification: { method: 'code' | 'date_of_birth' | 'national_id'; channels: string[] };
    changes: { cancel: boolean; reschedule: boolean; until_hours: number };
    session_minutes: number;
    services: Named[];
    places: { id: string; name: string; name_local: string | null; address: string | null; services: string[] }[];
}

export interface PublicPerson {
    name: string;
    employee_no: string | null;
    family_members: { id: string; name: string; relation: string }[];
}

/** One of the person's own appointments, as they see it. */
export interface PublicAppointment {
    id: string;
    number: string;
    status: Named & { open: boolean };
    service: Named;
    location?: { id: string; name: string; name_local: string | null; address: string | null };
    family_member: { id: string; name: string; relation: string } | null;
    date: string;
    starts_at: string | null;
    ends_at: string | null;
    note: string | null;
    cancel_reason: string | null;
    actions: { cancel: Verdict; move: Verdict };
}

const base = (organization: string) => `/public/${encodeURIComponent(organization)}/booking`;
const storageKey = (organization: string) => `booking-session:${organization}`;

/** The tab's session token for an organization, or null. */
export function sessionToken(organization: string): string | null {
    try {
        return sessionStorage.getItem(storageKey(organization));
    } catch {
        return null;
    }
}

export function keepSession(organization: string, token: string | null): void {
    try {
        if (token) {
            sessionStorage.setItem(storageKey(organization), token);
        } else {
            sessionStorage.removeItem(storageKey(organization));
        }
    } catch {
        /* storage unavailable: the session lasts until the page is left */
    }
}

const headers = (organization: string) => {
    const token = sessionToken(organization);

    return token ? { 'X-Booking-Session': token } : {};
};

export async function fetchPublicInfo(organization: string): Promise<PublicBookingInfo> {
    return (await http.get<{ data: PublicBookingInfo }>(base(organization))).data.data;
}

export async function identify(organization: string, employeeNo: string): Promise<{ challenge: string; method: PublicBookingInfo['verification']['method']; channels: string[]; expires_in: number }> {
    return (await http.post(`${base(organization)}/identify`, { employee_no: employeeNo })).data.data;
}

/** Answers the challenge; on success the session is kept for the tab. */
export async function verify(organization: string, answer: { challenge: string; code?: string; date_of_birth?: string; national_id?: string }): Promise<PublicPerson> {
    const { data } = await http.post<{ data: { token: string; person: PublicPerson } }>(`${base(organization)}/verify`, answer);
    keepSession(organization, data.data.token);

    return data.data.person;
}

/** Who the kept session stands for; null when there is none or it has ended. */
export async function currentPerson(organization: string): Promise<PublicPerson | null> {
    if (!sessionToken(organization)) {
        return null;
    }
    try {
        return (await http.get<{ data: { person: PublicPerson } }>(`${base(organization)}/me`, { headers: headers(organization) })).data.data.person;
    } catch {
        keepSession(organization, null);

        return null;
    }
}

export async function signOut(organization: string): Promise<void> {
    await http.post(`${base(organization)}/sign-out`, {}, { headers: headers(organization) }).catch(() => undefined);
    keepSession(organization, null);
}

/** Free times on the link, in AvailabilityWeek's shape (the place and query it asks with). */
export function publicAvailability(organization: string, except?: string | null) {
    return async (placeId: string, query: AvailabilityQuery): Promise<Availability> =>
        (await http.get<{ data: Availability }>(`${base(organization)}/availability`, { params: { location: placeId, service: query.service, from: query.from, to: query.to, except: except || undefined }, headers: headers(organization) })).data.data;
}

export async function myAppointments(organization: string): Promise<PublicAppointment[]> {
    return (await http.get<{ data: PublicAppointment[] }>(`${base(organization)}/appointments`, { headers: headers(organization) })).data.data;
}

export async function bookPublic(organization: string, input: { location: string; service: string; family_member: string | null; date: string; time: string | null; note: string | null }): Promise<PublicAppointment> {
    return (await http.post<{ data: PublicAppointment }>(`${base(organization)}/appointments`, input, { headers: headers(organization) })).data.data;
}

export async function cancelPublic(organization: string, id: string, reason: string | null): Promise<PublicAppointment> {
    return (await http.post<{ data: PublicAppointment }>(`${base(organization)}/appointments/${id}/cancel`, { reason }, { headers: headers(organization) })).data.data;
}

export async function movePublic(organization: string, id: string, input: { location: string; date: string; time: string | null }): Promise<PublicAppointment> {
    return (await http.patch<{ data: PublicAppointment }>(`${base(organization)}/appointments/${id}`, input, { headers: headers(organization) })).data.data;
}
