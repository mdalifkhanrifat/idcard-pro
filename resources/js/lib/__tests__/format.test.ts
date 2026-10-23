import { afterEach, describe, expect, it, vi } from 'vitest';
import { formatLongDate } from '@/lib/format';

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
