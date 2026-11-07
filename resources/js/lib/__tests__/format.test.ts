import { afterEach, describe, expect, it, vi } from 'vitest';
import { formatDuration, formatLongDate } from '@/lib/format';

const locale = vi.hoisted(() => ({ value: 'en' }));
vi.mock('@/lib/i18n', () => ({ i18n: { global: { locale } } }));

describe('a calendar day in full', () => {
    afterEach(() => {
        locale.value = 'en';
    });

    it('names the weekday of the day itself, never shifted by a time zone', () => {
        expect(formatLongDate('2026-10-19')).toBe('Monday, 19 October 2026');
        expect(formatLongDate('2026-10-19T00:00:00+06:00', false)).toBe('Monday 19 October');
    });

    it('is written in the reader\'s language and digits', () => {
        locale.value = 'bn';

        expect(formatLongDate('2026-10-19')).toContain('১৯');
        expect(formatLongDate('2026-10-19')).toContain('সোমবার');
    });
});

describe('a length of time', () => {
    afterEach(() => {
        locale.value = 'en';
    });

    it('is minutes under an hour and hours after, roughly', () => {
        expect(formatDuration(60)).toBe('1 minute');
        expect(formatDuration(360)).toBe('6 minutes');
        expect(formatDuration(20)).toBe('1 minute');
        expect(formatDuration(9360)).toBe('2.6 hours');
        expect(formatDuration(30960)).toBe('8.6 hours');
        expect(formatDuration(36 * 3600 + 1200)).toBe('36 hours');
    });

    it('is written in the reader\'s language and digits', () => {
        locale.value = 'bn';

        expect(formatDuration(30960)).toBe('৮.৬ ঘণ্টা');
        expect(formatDuration(2160)).toBe('৩৬ মিনিট');
    });
});
