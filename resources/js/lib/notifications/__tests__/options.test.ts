import { describe, expect, it } from 'vitest';
import { nextNumber } from '@/lib/notifications/options';

const days = { min: 1, max: 365, default: [30] };

describe('nextNumber', () => {
    it('starts with the default, then a quarter of the nearest one there', () => {
        expect(nextNumber([], days)).toBe(30);
        expect(nextNumber([30], days)).toBe(7);
        expect(nextNumber([30, 7], days)).toBe(1);
        expect(nextNumber([60], days)).toBe(15);
    });

    it('takes the closest free number within the range', () => {
        expect(nextNumber([1, 30], days)).toBe(2);
        expect(nextNumber([1, 2, 3], { min: 1, max: 3, default: [1] })).toBe(1);
        expect(nextNumber([5], { min: 5, max: 6, default: [5] })).toBe(6);
    });
});
