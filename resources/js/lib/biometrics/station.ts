import type { BiometricOptions } from '@/types';

/**
 * Per-station settings kept in this browser: the address of a vendor's local service when it differs
 * from the registry's default. Only addresses on this computer are accepted, so captures never come
 * from (or requests never go to) another machine.
 */
const key = (driver: string) => `biometric-station:${driver}:url`;

export function isLoopbackUrl(value: string): boolean {
    try {
        const url = new URL(value);
        const host = url.hostname.replace(/^\[|\]$/g, '');

        return ['http:', 'https:'].includes(url.protocol) && (host === 'localhost' || host === '::1' || /^127(\.\d{1,3}){3}$/.test(host));
    } catch {
        return false;
    }
}

/** The address this station uses for a driver's local service; null when the driver has none. */
export function stationUrl(driver: string, options: BiometricOptions): string | null {
    const fallback = options.drivers[driver]?.local_url ?? null;
    if (fallback === null) {
        return null;
    }
    try {
        const saved = localStorage.getItem(key(driver));

        return saved && isLoopbackUrl(saved) ? saved : fallback;
    } catch {
        return fallback;
    }
}

/** Save this station's address for a driver; null goes back to the registry's default. */
export function setStationUrl(driver: string, url: string | null): void {
    try {
        if (url === null) {
            localStorage.removeItem(key(driver));
        } else if (isLoopbackUrl(url)) {
            localStorage.setItem(key(driver), url.replace(/\/+$/, ''));
        }
    } catch {
        // Private windows may refuse; the default address is used
    }
}
