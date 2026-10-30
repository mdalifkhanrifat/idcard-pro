import { describe, expect, it } from 'vitest';
import { addressError, endsSoon, parseAddresses } from '../tokens';

describe('parseAddresses', () => {
    it('takes one a line, commas and blank lines too', () => {
        expect(parseAddresses('203.0.113.7\n\n198.51.100.0/24, 2001:db8::/32 ')).toEqual(['203.0.113.7', '198.51.100.0/24', '2001:db8::/32']);
    });

    it('reads nothing as no list', () => {
        expect(parseAddresses('  \n')).toEqual([]);
        expect(parseAddresses(null)).toEqual([]);
    });
});

describe('endsSoon', () => {
    const now = new Date('2026-10-30T10:00:00Z');

    it('speaks up within the last thirty days only', () => {
        expect(endsSoon('2026-11-29T09:00:00Z', now)).toBe(true);
        expect(endsSoon('2026-12-30T10:00:00Z', now)).toBe(false);
    });

    it('leaves out one already over, and one with no end', () => {
        expect(endsSoon('2026-10-29T10:00:00Z', now)).toBe(false);
        expect(endsSoon(null, now)).toBe(false);
    });
});

describe('addressError', () => {
    it('finds the message of a wrong address, whichever line', () => {
        expect(addressError({ name: ['x'], 'allowed_ips.2': ['Not an address'] })).toBe('Not an address');
        expect(addressError({ allowed_ips: ['Too many'] })).toBe('Too many');
        expect(addressError({ name: ['x'] })).toBeUndefined();
    });
});
