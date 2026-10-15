/**
 * Helpers for writing a message's texts: filling in placeholders as the server does
 * (App\Services\Notifications\NotificationSettings::fill), finding ones an event does not offer, and counting
 * an SMS's characters and parts.
 */

/** {holder.name}, {card.number}… — the server's pattern, so a preview fills exactly what a message will. */
const PLACEHOLDER = /\{([a-z0-9_]+(?:\.[a-z0-9_]+)*)\}/g;

/** A text with its placeholders filled in; unknown ones stay as written, values are never read again. */
export function fillPlaceholders(text: string, values: Record<string, string>): string {
    return text.replace(PLACEHOLDER, (match, key: string) => (Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match));
}

/** Placeholders a text uses that are not offered (e.g. a misspelt {card.numbr}), each once, in order. */
export function unknownPlaceholders(text: string, offered: readonly string[]): string[] {
    const unknown = [...text.matchAll(PLACEHOLDER)].map((match) => match[1]).filter((key) => !offered.includes(key));

    return [...new Set(unknown)];
}

/** The GSM 03.38 alphabet: one character each (the escape character itself left out). */
const GSM_BASIC = new Set(
    '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà',
);

/** Its extension: two characters each (an escape, then the character). */
const GSM_EXTENDED = new Set('^{}\\[~]|€\f');

/** Characters per SMS: in one part alone, and in each part of a longer message (the rest joins them up). */
const LIMITS = {
    gsm7: { single: 160, multi: 153 },
    unicode: { single: 70, multi: 67 },
} as const;

export interface SmsParts {
    /** GSM-7 for plain Latin text; Unicode (UCS-2) as soon as one character is outside it, e.g. Bangla */
    encoding: keyof typeof LIMITS;
    /** Characters as the network counts them (a GSM extension character or an emoji counts two) */
    length: number;
    /** Parts the message is sent in (each one charged); 0 when empty */
    parts: number;
    /** Characters a part holds at this length */
    perPart: number;
    /** Characters left in the last part before another one starts */
    remaining: number;
}

/**
 * How an SMS goes over the network: its encoding, length and parts. A character is never split between two
 * parts, as phones and providers do, so a long message may take a part more than its length alone suggests.
 */
export function smsParts(text: string): SmsParts {
    const characters = [...text];
    const encoding: SmsParts['encoding'] = characters.every((character) => GSM_BASIC.has(character) || GSM_EXTENDED.has(character)) ? 'gsm7' : 'unicode';
    // GSM: septets; Unicode: UTF-16 units (a character outside the basic plane takes two)
    const sizes = characters.map((character) => (encoding === 'gsm7' ? (GSM_EXTENDED.has(character) ? 2 : 1) : character.length));
    const length = sizes.reduce((sum, size) => sum + size, 0);
    const limits = LIMITS[encoding];

    if (length <= limits.single) {
        return { encoding, length, parts: length === 0 ? 0 : 1, perPart: limits.single, remaining: limits.single - length };
    }

    let parts = 1;
    let used = 0;
    for (const size of sizes) {
        if (used + size > limits.multi) {
            parts++;
            used = 0;
        }
        used += size;
    }

    return { encoding, length, parts, perPart: limits.multi, remaining: limits.multi - used };
}
