import { i18n } from './i18n';

/** A count in the reader's digits (e.g. ১০ in Bangla). */
/**
 * Digits of a text (e.g. a time "09:30") in the reader's script, as formatNumber writes numbers: the browser's
 * own digits for the language (Bangla ০–৯), so a new language needs nothing here.
 */
export function localDigits(text: string): string {
    const digits = new Intl.NumberFormat(i18n.global.locale.value === 'bn' ? 'bn-BD' : 'en', { useGrouping: false });

    return text.replace(/\d/g, (digit) => digits.format(Number(digit)));
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat(i18n.global.locale.value === 'bn' ? 'bn-BD' : 'en').format(value);
}

/**
 * A moment in the reader's language, on a 24-hour clock (as documents print it; Bangla would otherwise end
 * in an English "AM/PM"); in the given time zone, else the browser's.
 */
export function formatDateTime(value: string | null | undefined, timeZone?: string): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(i18n.global.locale.value === 'bn' ? 'bn-BD' : 'en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
        hourCycle: 'h23',
        timeZone,
    }).format(new Date(value));
}

export function formatRelative(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(i18n.global.locale.value === 'bn' ? 'bn' : 'en', { numeric: 'auto' });
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31536000],
        ['month', 2592000],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return rtf.format(Math.round(seconds / size), unit);
        }
    }

    return rtf.format(seconds, 'second');
}

/** Calendar date (`YYYY-MM-DD`), shown without any timezone shift. */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return new Intl.DateTimeFormat(i18n.global.locale.value === 'bn' ? 'bn-BD' : 'en-GB', { dateStyle: 'medium' }).format(new Date(year, month - 1, day));
}

export function formatBytes(bytes: number): string {
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}
