import { describe, expect, it } from 'vitest';
import { filtersFor, namesDays, scopeOf, statusTone, timeRange } from '@/lib/appointments/bookings';

describe('the list\'s days', () => {
    it('reads the scope from the date filters, and back', () => {
        expect(scopeOf({ from: '2026-10-18', to: '2026-10-18', past: '' })).toBe('day');
        expect(scopeOf({ from: '', to: '', past: '' })).toBe('upcoming');
        expect(scopeOf({ from: '', to: '', past: '1' })).toBe('all');
        // A range of several days is not one day
        expect(scopeOf({ from: '2026-10-18', to: '2026-10-20', past: '' })).toBe('upcoming');

        for (const scope of ['day', 'upcoming', 'all'] as const) {
            expect(scopeOf(filtersFor(scope, '2026-10-18', '2026-10-17'))).toBe(scope);
        }
        expect(filtersFor('day', '2026-10-18', '2026-10-17')).toEqual({ from: '2026-10-18', to: '2026-10-18', past: '' });
    });

    it('keeps every scope in the link, so a reload shows the same days', () => {
        // Each scope leaves at least one date filter with a value (empty ones are not written to the link)
        for (const scope of ['day', 'upcoming', 'all'] as const) {
            const query = Object.fromEntries(Object.entries(filtersFor(scope, '2026-10-18', '2026-10-17')).filter(([, v]) => v !== '').map(([k, v]) => [`f_${k}`, v]));
            expect(namesDays(query)).toBe(true);
        }
        expect(namesDays({})).toBe(false);
        expect(namesDays({ f_status: 'pending' })).toBe(false);
        expect(namesDays({ ap_f_past: '1' }, 'ap_')).toBe(true);
    });
});

describe('an appointment as a row shows it', () => {
    it('reads its time on the organization\'s clock, never the browser\'s', () => {
        expect(timeRange({ starts_at: '2026-10-18T09:30:00+06:00', ends_at: '2026-10-18T10:00:00+06:00' })).toBe('09:30–10:00');
        // Ending at midnight
        expect(timeRange({ starts_at: '2026-10-18T23:30:00+06:00', ends_at: '2026-10-19T00:00:00+06:00' })).toBe('23:30–00:00');
        expect(timeRange({ starts_at: null, ends_at: null })).toBeNull();
    });

    it('gives each status a colour, and an unknown one a plain one', () => {
        expect(statusTone('pending')).toBe('warning');
        expect(statusTone('checked_in')).toBe('success');
        expect(statusTone('no_show')).toBe('danger');
        expect(statusTone('something_new')).toBe('neutral');
    });
});
