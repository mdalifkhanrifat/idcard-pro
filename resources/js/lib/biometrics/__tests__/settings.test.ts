import { describe, expect, it } from 'vitest';
import { appendBiometricSettings } from '../settings';
import type { TenantBiometrics } from '@/types';

function settings(overrides: Partial<TenantBiometrics> = {}): TenantBiometrics {
    return {
        enabled: true,
        modalities: {
            fingerprint: { mode: 'required', count: 2, positions: ['right_thumb', 'left_thumb'], substitutes: true, min_quality: 40, max_attempts: 3, drivers: ['upload'] },
        },
        family_members: false,
        required_before: ['issue'],
        print_on_card: true,
        print_source: 'print',
        ...overrides,
    };
}

describe('appendBiometricSettings', () => {
    it('sends the organization-wide choices, including which image a card prints', () => {
        const body = new FormData();
        appendBiometricSettings(body, settings());

        expect(body.get('biometrics[print_on_card]')).toBe('1');
        expect(body.get('biometrics[print_source]')).toBe('print');
        expect(body.getAll('biometrics[required_before][]')).toEqual(['issue']);
        expect(body.getAll('biometrics[modalities][fingerprint][positions][]')).toEqual(['right_thumb', 'left_thumb']);
    });

    it('keeps the kinds as they are while biometrics are off, but still sends the card printing choice', () => {
        const body = new FormData();
        appendBiometricSettings(body, settings({ enabled: false, print_source: 'issue' }));

        expect(body.get('biometrics[enabled]')).toBe('0');
        expect(body.get('biometrics[print_source]')).toBe('issue');
        expect(body.has('biometrics[modalities][fingerprint][mode]')).toBe(false);
    });
});
