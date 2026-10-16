import { describe, expect, it } from 'vitest';
import { blankClosure, byDayOfYear, closureDates, closureDays, closureForm, closureInput, type AppointmentClosure } from '@/lib/appointments/closures';

const closure = (values: Partial<AppointmentClosure>): AppointmentClosure => ({ id: 'x', location: null, date_from: '2026-11-01', date_to: '2026-11-01', yearly: false, reason: null, reason_local: null, ...values });

describe('closureInput', () => {
    it('sends everything for a new closure: an empty last day is the first, empty reasons and place are null', () => {
        const form = { ...blankClosure(), date_from: '2026-12-16', reason: '  Victory Day ' };

        expect(closureInput(form, null)).toEqual({ location: null, date_from: '2026-12-16', date_to: '2026-12-16', yearly: false, reason: 'Victory Day', reason_local: null });
    });

    it('sends only what changed of a closure', () => {
        const before = closureForm(closure({ location: { id: 'p1', name: 'Head office', name_local: null }, date_from: '2026-11-01', date_to: '2026-11-02', reason: 'Audit' }));

        expect(closureInput({ ...before, reason: 'Audit ' }, before)).toEqual({});
        expect(closureInput({ ...before, location: '', reason_local: 'নিরীক্ষা' }, before)).toEqual({ location: null, reason_local: 'নিরীক্ষা' });
        // Emptying the last day makes it one day
        expect(closureInput({ ...before, date_to: '' }, before)).toEqual({ date_to: '2026-11-01' });
    });

    it('shows a one-day closure with an empty last day', () => {
        expect(closureForm(closure({ date_from: '2026-03-26', date_to: '2026-03-26' })).date_to).toBe('');
        expect(closureForm(closure({ date_from: '2026-03-26', date_to: '2026-03-27' })).date_to).toBe('2026-03-27');
    });
});

describe('closure days', () => {
    it('counts both days, across months and leap days', () => {
        expect(closureDays('2026-11-01', '2026-11-01')).toBe(1);
        expect(closureDays('2026-12-31', '2027-01-02')).toBe(3);
        expect(closureDays('2028-02-28', '2028-03-01')).toBe(3);
    });

    it('orders yearly closures by the day of the year, whatever year they started', () => {
        const list = [closure({ id: 'dec', date_from: '2024-12-16' }), closure({ id: 'feb', date_from: '2027-02-21' }), closure({ id: 'mar', date_from: '2026-03-26' })];

        expect(byDayOfYear(list).map((item) => item.id)).toEqual(['feb', 'mar', 'dec']);
    });

    it('writes the days in the reader\'s language, a yearly closure without its year', () => {
        expect(closureDates(closure({ date_from: '2026-11-01', date_to: '2026-11-01' }), 'en')).toBe('1 Nov 2026');
        // Spaces around the dash differ between ICU versions
        expect(closureDates(closure({ date_from: '2026-11-01', date_to: '2026-11-02' }), 'en')).toMatch(/^1\s*–\s*2 Nov 2026$/);
        expect(closureDates(closure({ date_from: '2026-02-21', date_to: '2026-02-21', yearly: true }), 'en')).toBe('21 February');
        expect(closureDates(closure({ date_from: '2026-12-31', date_to: '2027-01-02', yearly: true }), 'en')).toMatch(/^31 December\s*–\s*2 January$/);
        expect(closureDates(closure({ date_from: '2026-02-21', date_to: '2026-02-21', yearly: true }), 'bn')).toBe('২১ ফেব্রুয়ারি');
    });
});
