import type { EventOptionDescription } from '@/lib/notifications/api';

/**
 * A sensible next number for a list setting of an event (e.g. days before a card's last day), not one already
 * there and within the setting's range: its default first, then a quarter of the nearest one there (30 → 7,
 * 7 → 1), the closest free number to that.
 */
export function nextNumber(taken: readonly number[], option: Pick<EventOptionDescription, 'min' | 'max' | 'default'>): number {
    const min = option.min ?? 1;
    const max = option.max ?? min;
    const defaults = Array.isArray(option.default) ? option.default : [];
    const start = taken.length === 0 ? (defaults[0] ?? min) : Math.max(min, Math.floor(Math.min(...taken) / 4));
    for (let distance = 0; distance <= max - min; distance++) {
        for (const candidate of [start - distance, start + distance]) {
            if (candidate >= min && candidate <= max && !taken.includes(candidate)) {
                return candidate;
            }
        }
    }

    return start;
}
