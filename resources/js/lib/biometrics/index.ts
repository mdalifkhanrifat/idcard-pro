import type { BiometricOptions } from '@/types';
import type { BiometricDriver } from './types';

export * from './types';
export { acceptFor, detectImageFormat } from './files';

/**
 * Browser drivers by their key in config/biometrics.php. Each driver is its own module, loaded only
 * when a station uses it, so adding a scanner brand means adding a module and one line here (or
 * calling `registerBiometricDriver` from a plug-in) without touching the screens. A driver the
 * registry offers but this build lacks is simply not found, and the station says it is not available.
 */
type DriverLoader = () => Promise<BiometricDriver>;

const loaders = new Map<string, DriverLoader>([
    ['simulator', async () => (await import('./drivers/simulator')).simulatorDriver],
    ['upload', async () => (await import('./drivers/upload')).uploadDriver],
    ['bridge', async () => (await import('./drivers/bridge')).bridgeDriver],
]);
const loaded = new Map<string, Promise<BiometricDriver>>();

export function registerBiometricDriver(id: string, loader: DriverLoader): void {
    loaders.set(id, loader);
    loaded.delete(id);
}

export function hasBiometricDriver(name: string): boolean {
    return loaders.has(name);
}

/** The driver's module, loaded once; undefined when this build has no such driver. */
export function loadBiometricDriver(name: string): Promise<BiometricDriver | undefined> {
    const loader = loaders.get(name);
    if (!loader) {
        return Promise.resolve(undefined);
    }
    if (!loaded.has(name)) {
        // A failed load (e.g. offline) may be tried again
        loaded.set(name, loader().catch((error: unknown) => {
            loaded.delete(name);
            throw error;
        }));
    }

    return loaded.get(name)!;
}

/**
 * Drivers a station may use for a kind: allowed by the organization's settings, offered by this
 * installation and present in this browser build, in the organization's order.
 */
export function driversFor(modality: string, allowed: string[], options: BiometricOptions): string[] {
    return allowed.filter((name) => options.drivers[name]?.modalities.includes(modality) && loaders.has(name));
}
