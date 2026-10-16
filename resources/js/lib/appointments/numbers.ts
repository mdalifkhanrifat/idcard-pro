/**
 * A booking number as the organization's format makes it (the server's AppointmentNumbers): {YYYY}, {YY}, {MM}
 * of a day and one running {SEQ:n}; null when the format has no {SEQ:n} or a token the server does not take.
 */
export function numberSample(format: string, day: Date, sequence: number): string | null {
    const seq = [...format.matchAll(/\{SEQ:(\d{1,2})\}/g)];
    const leftover = format.replace(/\{(YYYY|YY|MM|SEQ:\d{1,2})\}/g, '');
    if (seq.length !== 1 || Number(seq[0][1]) < 1 || Number(seq[0][1]) > 10 || /[{}]/.test(leftover) || /[^A-Za-z0-9/\-_.]/.test(leftover)) {
        return null;
    }
    const year = String(day.getFullYear());

    return format
        .replaceAll('{YYYY}', year)
        .replaceAll('{YY}', year.slice(2))
        .replaceAll('{MM}', String(day.getMonth() + 1).padStart(2, '0'))
        .replace(/\{SEQ:(\d{1,2})\}/, (_, width: string) => String(sequence).padStart(Number(width), '0'));
}
