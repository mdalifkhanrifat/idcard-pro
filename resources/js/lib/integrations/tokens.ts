/** A token ending this soon is worth a word: the system behind it stops on that day. */
export const ENDS_SOON_DAYS = 30;

/** One address or range a line, as a person types or pastes them: commas and blank lines are fine too. */
export function parseAddresses(text: string | null | undefined): string[] {
    return (text ?? '').split(/[\s,]+/).map((address) => address.trim()).filter(Boolean);
}

export function endsSoon(expiresAt: string | null, now: Date = new Date()): boolean {
    if (expiresAt === null) {
        return false;
    }
    const left = new Date(expiresAt).getTime() - now.getTime();

    return left > 0 && left <= ENDS_SOON_DAYS * 24 * 60 * 60 * 1000;
}

/** The server names a wrong address by its place ("allowed_ips.2"); the form has one box for them all. */
export function addressError(errors: Record<string, string[]>): string | undefined {
    const key = Object.keys(errors).find((name) => name === 'allowed_ips' || name.startsWith('allowed_ips.'));

    return key === undefined ? undefined : errors[key][0];
}
