import { describe, expect, it } from 'vitest';
import { cardholderLink, statusTone } from '../messages';

describe('messages', () => {
    it('shows each status in its own tone', () => {
        expect([statusTone('sent'), statusTone('failed'), statusTone('skipped'), statusTone('queued')]).toEqual(['success', 'danger', 'warning', 'neutral']);
    });

    it('links a cardholder to their page, a family member to the family tab, and no one else', () => {
        expect(cardholderLink({ type: 'cardholder', id: 'c1', name: 'Rahim' })).toEqual({ path: '/cardholders/c1' });
        expect(cardholderLink({ type: 'family_member', id: 'f1', name: 'Karim', cardholder_id: 'c1' })).toEqual({ path: '/cardholders/c1', query: { tab: 'family' } });
        expect(cardholderLink({ type: 'user', id: 'u1', name: 'Owner' })).toBeNull();
        expect(cardholderLink(null)).toBeNull();
    });
});
