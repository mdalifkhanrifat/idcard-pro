import { describe, expect, it } from 'vitest';
import type { AppointmentOptions, AppointmentService, AppointmentSettings } from '@/lib/appointments/api';
import { serviceKeyFrom, serviceName, withRemovedServices } from '@/lib/appointments/services';

const KEY = '^[a-z][a-z0-9_]{1,39}$';
const service = (name: Record<string, string | null>, custom = false): AppointmentService => ({ enabled: true, custom, name, slots: 1, public: true });

describe('serviceKeyFrom', () => {
    it('makes a key from the first Latin name, and numbers it when taken', () => {
        expect(serviceKeyFrom(['গাড়ির পাস', 'Vehicle pass!'], [], KEY)).toBe('vehicle_pass');
        expect(serviceKeyFrom(['Vehicle pass'], ['vehicle_pass', 'vehicle_pass_2'], KEY)).toBe('vehicle_pass_3');
        expect(serviceKeyFrom(['  2nd Card — Café '], [], KEY)).toBe('nd_card_cafe');
    });

    it('falls back to visit for names without Latin letters', () => {
        expect(serviceKeyFrom(['গাড়ির পাস'], [], KEY)).toBe('visit');
        expect(serviceKeyFrom(['গাড়ির পাস', ''], ['visit'], KEY)).toBe('visit_2');
        expect(serviceKeyFrom(['x'], [], KEY)).toBe('visit');
    });
});

describe('serviceName', () => {
    const options = { services: { renewal: { slots: 1, names: { en: 'Card renewal', bn: 'কার্ড নবায়ন' } } } } as unknown as AppointmentOptions;

    it('names a kind as the server does', () => {
        expect(serviceName('renewal', service({ en: null, bn: null }), options, 'bn')).toBe('কার্ড নবায়ন');
        expect(serviceName('renewal', service({ en: 'Renew', bn: null }), options, 'en')).toBe('Renew');
        expect(serviceName('pass', service({ en: 'Pass', bn: null }, true), options, 'bn')).toBe('Pass');
        expect(serviceName('pass', service({ en: null, bn: null }, true), options, 'bn')).toBe('pass');
    });
});

describe('withRemovedServices', () => {
    it('sends a removed kind of visit as null, and leaves other changes alone', () => {
        const saved = { services: { renewal: service({}), pass: service({ en: 'Pass' }, true) } } as unknown as AppointmentSettings;

        expect(withRemovedServices({ services: { renewal: service({}) } }, saved)).toEqual({ services: { renewal: service({}), pass: null } });
        expect(withRemovedServices({ enabled: true }, saved)).toEqual({ enabled: true });
    });
});
