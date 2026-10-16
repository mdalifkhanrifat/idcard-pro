import { describe, expect, it } from 'vitest';
import { copyToOpenDays, endShown, endStored, hoursMistakes, type WeeklyHours } from '@/lib/appointments/places';

const WEEK = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

describe('hoursMistakes', () => {
    it('names the mistakes as the server does', () => {
        const hours: WeeklyHours = { mon: [['13:00', '09:00']], tue: [['09:00', '13:00'], ['12:00', '17:00']], wed: [['14:00', '17:00'], ['09:00', '13:00']], thu: [['22:00', '24:00']], fri: [['09:00', '09:00']] };

        expect(hoursMistakes(hours, WEEK)).toEqual({ 'hours.mon.0': 'period_order', 'hours.tue': 'periods_overlap', 'hours.fri.0': 'period_order' });
        expect(hoursMistakes({ sat: [['09:00', '13:00'], ['13:00', '17:00']] }, WEEK)).toEqual({});
    });
});

describe('copyToOpenDays', () => {
    it('copies a day to the other open days and leaves closed ones closed', () => {
        const hours: WeeklyHours = { mon: [['09:00', '13:00'], ['14:00', '17:00']], tue: [['10:00', '12:00']], fri: [], sun: [['08:00', '16:00']] };
        const copied = copyToOpenDays(hours, 'mon');

        expect(copied).toEqual({ mon: hours.mon, tue: hours.mon, fri: [], sun: hours.mon });
        expect(copied.tue).not.toBe(hours.mon);
    });
});

describe('midnight', () => {
    it('shows 24:00 as 00:00 and stores 00:00 as 24:00', () => {
        expect([endShown('24:00'), endShown('17:00'), endStored('00:00'), endStored('17:00')]).toEqual(['00:00', '17:00', '24:00', '17:00']);
    });
});
