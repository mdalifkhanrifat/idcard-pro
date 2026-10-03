import { i18n } from './i18n';

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(i18n.global.locale.value === 'bn' ? 'bn-BD' : 'en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
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
