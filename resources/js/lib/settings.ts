/**
 * The top-level parts of settings that differ from the saved ones (compared by value), for a partial change:
 * a part not changed is not sent, a changed one is sent whole.
 */
export function changedParts<T extends object>(draft: T, saved: T): Partial<T> {
    const before = saved as Record<string, unknown>;

    return Object.fromEntries(Object.entries(draft).filter(([key, value]) => JSON.stringify(value) !== JSON.stringify(before[key]))) as Partial<T>;
}
