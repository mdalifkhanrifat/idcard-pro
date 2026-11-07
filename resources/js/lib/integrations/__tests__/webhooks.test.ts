import { describe, expect, it } from 'vitest';
import { deliveryTone, groupState, knownEvents, lastTryAfter, prettyPayload, turnedItselfOff, withGroup, type WebhookEventGroup } from '../webhooks';

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

describe('the log', () => {
    it('colours a delivery by how it went', () => {
        expect(deliveryTone('delivered')).toBe('success');
        expect(deliveryTone('failed')).toBe('danger');
        expect(deliveryTone('queued')).toBe('neutral');
    });

    it('lays out what was sent, an empty object still an object', () => {
        expect(prettyPayload('{"data":{"custom_fields":{}}}')).toBe('{\n  "data": {\n    "custom_fields": {}\n  }\n}');
    });

    it('shows other text as it is, and nothing for nothing', () => {
        expect(prettyPayload('not json')).toBe('not json');
        expect(prettyPayload(null)).toBe('');
    });
});

describe('lastTryAfter', () => {
    const backoff = [60, 300, 1800, 7200, 21600];

    it('adds up the first waits only, as the server keeps them for fewer tries', () => {
        expect(lastTryAfter(6, backoff)).toBe(30960);
        expect(lastTryAfter(3, backoff)).toBe(360);
        expect(lastTryAfter(2, backoff)).toBe(60);
    });

    it('is no wait at all for a single try', () => {
        expect(lastTryAfter(1, backoff)).toBe(0);
        expect(lastTryAfter(0, backoff)).toBe(0);
    });
});
