import { describe, expect, it } from 'vitest';
import { downloadFilename } from '../download';

describe('downloadFilename', () => {
    it('reads quoted, plain and RFC 5987 names', () => {
        expect(downloadFilename('attachment; filename="cards-2026.pdf"', 'x')).toBe('cards-2026.pdf');
        expect(downloadFilename('attachment; filename=card-prints-2026-10-12-101500.xlsx', 'x')).toBe('card-prints-2026-10-12-101500.xlsx');
        expect(downloadFilename("attachment; filename=report.csv; filename*=UTF-8''%E0%A6%B0%E0%A6%BF.csv", 'x')).toBe('রি.csv');
    });

    it('falls back when the server gave no name', () => {
        expect(downloadFilename(undefined, 'report.csv')).toBe('report.csv');
        expect(downloadFilename('attachment', 'report.csv')).toBe('report.csv');
    });
});
