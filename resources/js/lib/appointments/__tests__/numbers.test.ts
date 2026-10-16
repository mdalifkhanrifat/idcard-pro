import { describe, expect, it } from 'vitest';
import { numberSample } from '@/lib/appointments/numbers';

describe('numberSample', () => {
    const day = new Date(2026, 2, 5);

    it('fills the tokens as the server does', () => {
        expect(numberSample('A-{YY}-{SEQ:5}', day, 42)).toBe('A-26-00042');
        expect(numberSample('VIS/{YYYY}{MM}/{SEQ:3}', day, 7)).toBe('VIS/202603/007');
        expect(numberSample('{SEQ:2}', day, 123)).toBe('123');
    });

    it('gives nothing for a format the server refuses', () => {
        for (const format of ['A-{YY}', '{SEQ:3}-{SEQ:3}', '{CODE}-{SEQ:4}', 'A {SEQ:4}', '{SEQ:0}', '{SEQ:11}']) {
            expect(numberSample(format, day, 1)).toBeNull();
        }
    });
});
