import { describe, expect, it } from 'vitest';
import bn from '@/locales/bn.json';
import en from '@/locales/en.json';

/** Every text's key ("nav.notifications"…), so one missing in a language shows by name. */
function keys(messages: Record<string, unknown>, prefix = ''): string[] {
    return Object.entries(messages).flatMap(([key, value]) =>
        value !== null && typeof value === 'object' ? keys(value as Record<string, unknown>, `${prefix}${key}.`) : [`${prefix}${key}`],
    );
}

describe('locales', () => {
    it('has every text of the screens in both English and Bangla', () => {
        const english = keys(en);
        const bangla = keys(bn);

        expect(english.filter((key) => !bangla.includes(key))).toEqual([]);
        expect(bangla.filter((key) => !english.includes(key))).toEqual([]);
    });
});
