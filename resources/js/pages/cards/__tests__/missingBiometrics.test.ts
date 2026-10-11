import { describe, expect, it, vi } from 'vitest';
import type { CardLayout, CardValues, DesignElement } from '@/types';

vi.mock('@/router', () => ({ router: {} }));
vi.mock('@/lib/http', () => ({ http: {} }));

const { missingBiometrics } = await import('../cards');
const { biometricFieldLabel } = await import('@/lib/biometrics/labels');

function element(field: string, hidden = false): DesignElement {
    return { side: 'front', type: 'biometric', field, label: null, content: null, qr_lines: null, image_url: null, x: 0, y: 0, width: 16, height: 20, style: hidden ? { hidden: true } : {} } as unknown as DesignElement;
}

function layout(...elements: DesignElement[]): CardLayout {
    return { size_mm: { width: 85.6, height: 54 }, orientation: 'landscape', is_double_sided: false, elements } as CardLayout;
}

const values = (biometrics: Record<string, string | null> | []): CardValues => ({ fields: {}, images: { photo: null, cardholder_signature: null, signatory_signature: null, biometrics } });

describe('missingBiometrics', () => {
    it('lists biometric elements with nothing printable, once each, ignoring hidden ones', () => {
        const card = layout(element('biometric.fingerprint.auto'), element('biometric.fingerprint.auto'), element('biometric.fingerprint.left_thumb'), element('biometric.fingerprint.left_index', true));

        expect(missingBiometrics(card, values({ 'biometric.fingerprint.auto': null, 'biometric.fingerprint.left_thumb': '/x', 'biometric.fingerprint.left_index': null }))).toEqual(['biometric.fingerprint.auto']);
    });

    it('does not warn when the organization does not print biometrics (the fields are left out)', () => {
        expect(missingBiometrics(layout(element('biometric.fingerprint.auto')), values([]))).toEqual([]);
        expect(missingBiometrics(layout(element('biometric.fingerprint.auto')), { fields: {}, images: { photo: null, cardholder_signature: null, signatory_signature: null } })).toEqual([]);
    });
});

describe('biometricFieldLabel', () => {
    const texts: Record<string, string> = { 'biometrics.modalities.fingerprint': 'Fingerprint', 'biometrics.autoPosition': 'First captured', 'biometrics.positions.right_thumb': 'Right thumb' };
    const t = (key: string) => texts[key] ?? key;
    const te = (key: string) => key in texts;

    it('names the kind and position, or the first captured for auto', () => {
        expect(biometricFieldLabel('biometric.fingerprint.auto', t, te)).toBe('Fingerprint · First captured');
        expect(biometricFieldLabel('biometric.fingerprint.right_thumb', t, te)).toBe('Fingerprint · Right thumb');
        // A kind added to the registry without a translation still reads well
        expect(biometricFieldLabel('biometric.palm.left_palm', t, te)).toBe('Palm · Left palm');
    });
});
