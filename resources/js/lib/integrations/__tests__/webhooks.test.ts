import { describe, expect, it } from 'vitest';
import { groupState, knownEvents, turnedItselfOff, withGroup, type WebhookEventGroup } from '../webhooks';

const cards: WebhookEventGroup = { key: 'card', name: 'Cards', allowed: true, events: [{ key: 'card.issued', name: 'Issued' }, { key: 'card.printed', name: 'Printed' }] };

describe('turnedItselfOff', () => {
    it('is off with a moment left by failing, not off by a person', () => {
        expect(turnedItselfOff({ enabled: false, disabled_at: '2026-11-01T10:00:00Z' })).toBe(true);
        expect(turnedItselfOff({ enabled: false, disabled_at: null })).toBe(false);
        expect(turnedItselfOff({ enabled: true, disabled_at: null })).toBe(false);
    });
});

describe('choosing a group of events', () => {
    it('says how much of it is chosen', () => {
        expect(groupState([], cards)).toBe('none');
        expect(groupState(['card.issued', 'other.event'], cards)).toBe('some');
        expect(groupState(['card.printed', 'card.issued'], cards)).toBe('all');
    });

    it('turns the whole group on or off and leaves the rest alone', () => {
        expect(withGroup(['other.event', 'card.issued'], cards, true)).toEqual(['other.event', 'card.issued', 'card.printed']);
        expect(withGroup(['other.event', 'card.issued'], cards, false)).toEqual(['other.event']);
    });

    it('drops an event no longer offered', () => {
        expect(knownEvents(['card.issued', 'gone.event'], [cards])).toEqual(['card.issued']);
    });
});
