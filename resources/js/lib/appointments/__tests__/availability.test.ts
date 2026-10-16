import { describe, expect, it } from 'vitest';
import { addDays, todayIn, wallClock } from '@/lib/appointments/availability';

describe('availability helpers', () => {
    it('moves by calendar days across months, years and leap days', () => {
        expect(addDays('2026-10-16', 6)).toBe('2026-10-22');
        expect(addDays('2026-12-29', 7)).toBe('2027-01-05');
        expect(addDays('2028-03-01', -1)).toBe('2028-02-29');
    });

    it('takes today in the organization\'s time zone, on either side of its midnight', () => {
        // 18:30 UTC on the 16th is 00:30 on the 17th in Dhaka, still the 16th in New York
        const now = new Date('2026-10-16T18:30:00Z');

        expect(todayIn('Asia/Dhaka', now)).toBe('2026-10-17');
        expect(todayIn('America/New_York', now)).toBe('2026-10-16');
    });

    it('reads a slot on the organization\'s wall clock, never the browser\'s', () => {
        expect(wallClock('2026-10-18T09:00:00+06:00')).toBe('09:00');
        expect(wallClock('2027-03-14T03:30:00-04:00')).toBe('03:30');
    });
});
