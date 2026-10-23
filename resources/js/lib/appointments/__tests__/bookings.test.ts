import { describe, expect, it } from 'vitest';
import { filtersFor, layoutActions, namesDays, scopeOf, statusTone, timeRange } from '@/lib/appointments/bookings';

describe('the list\'s days', () => {
    it('reads the scope from the date filters, and back', () => {
        expect(scopeOf({ from: '2026-10-18', to: '2026-10-18', past: '' })).toBe('day');
        expect(scopeOf({ from: '', to: '', past: '' })).toBe('upcoming');
        expect(scopeOf({ from: '', to: '', past: '1' })).toBe('all');
        // A range of several days is a week view, not one day
        expect(scopeOf({ from: '2026-10-18', to: '2026-10-20', past: '' })).toBe('week');

        for (const scope of ['day', 'week', 'upcoming', 'all'] as const) {
            expect(scopeOf(filtersFor(scope, '2026-10-18', '2026-10-17'))).toBe(scope);
        }
        expect(filtersFor('day', '2026-10-18', '2026-10-17')).toEqual({ from: '2026-10-18', to: '2026-10-18', past: '' });
        // Seven days from the one given, across a month's end
        expect(filtersFor('week', '2026-10-28', '2026-10-17')).toEqual({ from: '2026-10-28', to: '2026-11-03', past: '' });
    });

    it('keeps every scope in the link, so a reload shows the same days', () => {
        // Each scope leaves at least one date filter with a value (empty ones are not written to the link)
        for (const scope of ['day', 'week', 'upcoming', 'all'] as const) {
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

describe('an appointment\'s buttons', () => {
    const verdict = (allowed: boolean) => ({ allowed, reason: allowed ? null : 'Not now.' });
    const step = (key: string, correction = false, allowed = true) => ({ status: { key, name: key }, label: `To ${key}`, correction, ...verdict(allowed) });

    it('puts the steps ahead first, calling off apart and corrections last', () => {
        const laid = layoutActions({ move: verdict(true), note: verdict(true), cancel: null, steps: [step('checked_in'), step('no_show', false, false), step('cancelled'), step('pending', true)] });

        expect(laid.forward.map((s) => s.status.key)).toEqual(['checked_in', 'no_show']);
        // Only managing: calling off is the step itself, with its own name
        expect(laid.stop).toEqual({ via: 'status', label: 'To cancelled', allowed: true, reason: null });
        expect(laid.corrections.map((s) => s.status.key)).toEqual(['pending']);
    });

    it('calls off through cancel for those who book, once', () => {
        const laid = layoutActions({ move: verdict(true), note: verdict(true), cancel: verdict(false), steps: [step('checked_in')] });

        expect(laid.stop).toEqual({ via: 'cancel', label: null, allowed: false, reason: 'Not now.' });
        expect(laid.forward).toHaveLength(1);
    });

    it('leaves a restored cancelled one among the corrections, and offers nothing without actions', () => {
        // Back from cancelled is a correction, not calling off
        const laid = layoutActions({ move: null, note: null, cancel: null, steps: [step('confirmed', true)] });
        expect(laid.stop).toBeNull();
        expect(laid.move).toBeNull();
        expect(laid.corrections).toHaveLength(1);

        expect(layoutActions(undefined)).toEqual({ forward: [], move: null, stop: null, corrections: [] });
    });

    it('does not offer to move or call off a finished one, nor say why not — only its corrections', () => {
        const actions = { move: verdict(false), note: verdict(false), cancel: verdict(false), steps: [step('confirmed', true)] };

        expect(layoutActions(actions, false).stop).toBeNull();
        expect(layoutActions(actions, false).move).toBeNull();
        expect(layoutActions(actions, true).move).toEqual(verdict(false));
        expect(layoutActions(actions, false).corrections).toHaveLength(1);
        // Still open (came in): it says why not
        expect(layoutActions(actions, true).stop).toMatchObject({ allowed: false, reason: 'Not now.' });
    });
});
