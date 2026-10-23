import { describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/http', () => ({ http: {}, toApiError: () => ({}) }));

const { keptAsRecord } = await import('../cardholder');

describe('a cardholder kept as a record', () => {
    const t = (key: string) => key;

    it('says why it cannot be deleted: cards first, then appointments', () => {
        expect(keptAsRecord({ has_cards: true, has_appointments: true }, t)).toBe('cardholders.hasCards');
        expect(keptAsRecord({ has_cards: false, has_appointments: true }, t)).toBe('cardholders.hasAppointments');
        expect(keptAsRecord({ has_cards: false, has_appointments: false }, t)).toBeNull();
        // Not answered (e.g. an older answer): nothing to keep it for, the server still guards
        expect(keptAsRecord({}, t)).toBeNull();
    });
});
