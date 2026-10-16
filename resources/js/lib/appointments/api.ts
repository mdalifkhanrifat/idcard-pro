import { http } from '@/lib/http';

/** A kind of visit as the organization chose it (the registry's, renamed or not, and its own). */
export interface AppointmentService {
    enabled: boolean;
    /** The organization's own kind (may be removed), not the registry's. */
    custom: boolean;
    /** Language => its name; null = the default name. */
    name: Record<string, string | null>;
    /** Time slots one booking takes (slot scheduling). */
    slots: number;
    /** Offered on the public link too (staff can always book it). */
    public: boolean;
    /** The organization's own kinds: their order, as added (1 on). */
    position?: number;
}

/** tenants.settings.appointments, always complete (the server fills in defaults). */
export interface AppointmentSettings {
    enabled: boolean;
    /** Who books: `staff`, `public` (the public link). */
    booking: string[];
    /** `slot` (a time of the day) or `day` (served in turn). */
    scheduling: string;
    slot_minutes: number;
    /** `auto` or `approval`. */
    confirmation: string;
    family_members: boolean;
    open_per_service: number;
    notice_hours: number;
    days_ahead: number;
    /** What people may do themselves through the public link, until some hours before. */
    changes: { cancel: boolean; reschedule: boolean; until_hours: number };
    verification: { method: string; code_channels: string[]; code_minutes: number; code_attempts: number };
    /** Hours before an appointment a reminder goes, largest first (at most three). */
    reminders: number[];
    services: Record<string, AppointmentService>;
}

/** What the settings can be set to, named in the reader's language. */
export interface AppointmentOptions {
    booking: string[];
    scheduling: string[];
    confirmation: string[];
    verification: string[];
    code_channels: string[];
    languages: string[];
    labels: Record<'booking' | 'scheduling' | 'confirmation' | 'verification' | 'code_channels' | 'languages', Record<string, string>>;
    /** [min, max] of what may be set. */
    limits: Record<string, [number, number]>;
    custom_services: { max: number; key: string };
    /** The registry's kinds of visit: slots and default names in every language. */
    services: Record<string, { slots: number; names: Record<string, string> }>;
    /** Places: weekdays (ISO keys, in order), a new place's week and capacities, periods a day, places at most. */
    locations: { weekdays: string[]; hours: Record<string, [string, string][]>; slot_capacity: number; day_capacity: number; periods_per_day: number; max: number };
}

/** How many places the organization has, and has on (read only; appointments need one on). */
export interface PlaceCounts {
    total: number;
    active: number;
}

export interface AppointmentSettingsResponse {
    appointments: AppointmentSettings;
    options: AppointmentOptions;
    places: PlaceCounts;
}

/** GET /appointments/settings (appointments.view or settings.update). */
export async function fetchAppointmentSettings(): Promise<AppointmentSettingsResponse> {
    const { data } = await http.get<{ data: AppointmentSettingsResponse }>('/appointments/settings');

    return data.data;
}

/** PUT /appointments/settings (settings.update): only what is sent changes; the saved settings come back. */
export async function saveAppointmentSettings(appointments: Partial<AppointmentSettings>): Promise<AppointmentSettingsResponse> {
    const { data } = await http.put<{ data: AppointmentSettingsResponse }>('/appointments/settings', { appointments });

    return data.data;
}
