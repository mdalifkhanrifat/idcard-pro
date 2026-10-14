import { describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/http', () => ({ http: {} }));
const locale = { value: 'en' };
vi.mock('@/lib/i18n', () => ({ i18n: { global: { locale } } }));

const { formatReportValue, reportSlug } = await import('../reports');

describe('formatReportValue', () => {
    it('shows empty values as a dash and text as is', () => {
        expect(formatReportValue({ type: 'text' }, null)).toBe('—');
        expect(formatReportValue({ type: 'text' }, '')).toBe('—');
        expect(formatReportValue({ type: 'text' }, 'Captain')).toBe('Captain');
    });

    it('shows days without a time zone shift and moments in the organization\'s time zone', () => {
        expect(formatReportValue({ type: 'date' }, '2026-01-05')).toBe('5 Jan 2026');
        // 18:30 UTC on 31 March is 00:30 on 1 April in Dhaka
        expect(formatReportValue({ type: 'datetime' }, '2026-03-31T18:30:00+00:00', 'Asia/Dhaka')).toBe('1 Apr 2026, 00:30');
        expect(formatReportValue({ type: 'number' }, 1200)).toBe('1,200');
    });

    it('writes Bangla moments on a 24-hour clock, without an English AM/PM', () => {
        locale.value = 'bn';
        try {
            expect(formatReportValue({ type: 'datetime' }, '2026-03-31T18:30:00+00:00', 'Asia/Dhaka')).toBe('১ এপ্রি, ২০২৬, ০০:৩০');
            expect(formatReportValue({ type: 'datetime' }, '2026-03-31T08:15:00+00:00', 'Asia/Dhaka')).toBe('৩১ মার্চ, ২০২৬, ১৪:১৫');
            expect(formatReportValue({ type: 'date' }, '2026-01-05')).toBe('৫ জানু, ২০২৬');
        } finally {
            locale.value = 'en';
        }
    });
});

describe('reportSlug', () => {
    it('turns registry keys into addresses', () => {
        expect(reportSlug('card_prints')).toBe('card-prints');
    });
});
