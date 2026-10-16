import type { AppointmentOptions, AppointmentService, AppointmentSettings } from '@/lib/appointments/api';

/**
 * A key for a new kind of visit of the organization's own, from its names: the first name with Latin letters or
 * digits made lower case with underscores ("Vehicle pass" → vehicle_pass), else `visit`; numbered when taken.
 * Within the server's shape (options.custom_services.key: a letter, then 1–39 letters, digits or underscores).
 */
export function serviceKeyFrom(names: string[], taken: string[], pattern: string): string {
    const shape = new RegExp(pattern);
    const slug = names
        .map((name) => name.normalize('NFKD').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^[^a-z]+|_+$/g, '').slice(0, 32))
        .find((candidate) => shape.test(candidate));
    const base = slug ?? 'visit';
    let key = base;
    for (let n = 2; taken.includes(key); n++) {
        key = `${base}_${n}`;
    }

    return key;
}

/**
 * A kind of visit's name in a language: the organization's own, else the registry's default, else its own in
 * another language, else its key (as the server names it).
 */
export function serviceName(key: string, service: AppointmentService, options: AppointmentOptions, language: string): string {
    return service.name[language] || options.services[key]?.names[language] || Object.values(service.name).find((name) => !!name) || key;
}

/**
 * The changed settings as the server takes them: a kind of visit removed from the draft is sent as null (a
 * partial change otherwise keeps what is not sent).
 */
export function withRemovedServices(changes: Partial<AppointmentSettings>, saved: AppointmentSettings): Partial<AppointmentSettings> {
    if (!changes.services) {
        return changes;
    }
    const removed = Object.keys(saved.services).filter((key) => !(key in changes.services!));

    return { ...changes, services: { ...changes.services, ...Object.fromEntries(removed.map((key) => [key, null])) } as AppointmentSettings['services'] };
}
