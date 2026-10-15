import { describe, expect, it } from 'vitest';
import { fillPlaceholders, smsParts, unknownPlaceholders } from '../text';

describe('fillPlaceholders', () => {
    it('fills known placeholders and leaves unknown ones as written, like the server', () => {
        expect(fillPlaceholders('Hi {holder.name}, card {card.number} {nope} {Holder.name} { card.number }', { 'holder.name': 'Rahim', 'card.number': 'EMP/26000123' }))
            .toBe('Hi Rahim, card EMP/26000123 {nope} {Holder.name} { card.number }');
    });

    it('takes values as plain text: never read again, no replacement patterns, no inherited keys', () => {
        expect(fillPlaceholders('{holder.name} {card.number} {constructor}', { 'holder.name': '{card.number} $& $1', 'card.number': 'C-1' }))
            .toBe('{card.number} $& $1 C-1 {constructor}');
    });
});

describe('unknownPlaceholders', () => {
    it('lists each placeholder that is not offered once, in order', () => {
        expect(unknownPlaceholders('{card.numbr} {holder.name} {x} {card.numbr}', ['holder.name', 'card.number'])).toEqual(['card.numbr', 'x']);
        expect(unknownPlaceholders('{holder.name} { x } {X}', ['holder.name'])).toEqual([]);
    });
});

describe('smsParts', () => {
    it('counts plain Latin text as GSM-7: 160 characters alone, 153 in each part of a longer one', () => {
        expect(smsParts('')).toEqual({ encoding: 'gsm7', length: 0, parts: 0, perPart: 160, remaining: 160 });
        expect(smsParts('a'.repeat(160))).toEqual({ encoding: 'gsm7', length: 160, parts: 1, perPart: 160, remaining: 0 });
        expect(smsParts('a'.repeat(161))).toEqual({ encoding: 'gsm7', length: 161, parts: 2, perPart: 153, remaining: 145 });
        expect(smsParts('a'.repeat(307)).parts).toBe(3);
    });

    it('counts a GSM extension character as two and never splits it between parts', () => {
        expect(smsParts('€'.repeat(80))).toMatchObject({ encoding: 'gsm7', length: 160, parts: 1 });
        expect(smsParts('{}'.repeat(41))).toMatchObject({ length: 164, parts: 2 });
        // 152 + 2 would split the euro sign: it starts the second part
        expect(smsParts(`${'a'.repeat(152)}€${'a'.repeat(5)}`)).toMatchObject({ length: 159, parts: 1 });
        expect(smsParts(`${'a'.repeat(152)}€${'a'.repeat(10)}`)).toMatchObject({ length: 164, parts: 2, remaining: 153 - 12 });
    });

    it('counts Bangla, or anything outside GSM-7, as Unicode: 70 alone, 67 in each part', () => {
        expect(smsParts('ক'.repeat(70))).toEqual({ encoding: 'unicode', length: 70, parts: 1, perPart: 70, remaining: 0 });
        expect(smsParts('ক'.repeat(71))).toEqual({ encoding: 'unicode', length: 71, parts: 2, perPart: 67, remaining: 63 });
        // One Bangla letter in English text makes the whole message Unicode
        expect(smsParts(`${'a'.repeat(100)}ক`)).toMatchObject({ encoding: 'unicode', parts: 2 });
        // An emoji takes two units and is not split
        expect(smsParts(`${'a'.repeat(66)}😀`)).toMatchObject({ encoding: 'unicode', length: 68, parts: 1 });
        expect(smsParts(`${'a'.repeat(66)}😀${'a'.repeat(3)}`)).toMatchObject({ length: 71, parts: 2, remaining: 67 - 5 });
    });
});
