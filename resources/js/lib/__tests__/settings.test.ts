import { describe, expect, it } from 'vitest';
import { changedParts } from '@/lib/settings';

describe('changedParts', () => {
    const saved = { enabled: false, booking: ['staff'], changes: { cancel: true, until_hours: 2 }, reminders: [24], services: { renewal: { slots: 1 } } };

    it('keeps only the parts that changed, each whole', () => {
        const draft = { ...JSON.parse(JSON.stringify(saved)), enabled: true, changes: { cancel: true, until_hours: 4 } };

        expect(changedParts(draft, saved)).toEqual({ enabled: true, changes: { cancel: true, until_hours: 4 } });
    });

    it('sees an emptied list and a reordered one, and nothing when nothing changed', () => {
        expect(changedParts({ ...saved, booking: [] }, saved)).toEqual({ booking: [] });
        expect(changedParts({ ...saved, reminders: [24, 2] }, saved)).toEqual({ reminders: [24, 2] });
        expect(changedParts(JSON.parse(JSON.stringify(saved)), saved)).toEqual({});
    });
});
