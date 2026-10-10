import type { BiometricOptions } from '@/types';
import { simulatorDriver } from './drivers/simulator';
import { uploadDriver } from './drivers/upload';
import type { BiometricDriver } from './types';

export * from './types';
export { acceptFor, detectImageFormat } from './files';

/**
 * Browser drivers by their key in config/biometrics.php. A driver the registry offers but this
 * browser build does not have yet (e.g. a scanner brand added later) is simply not found, and the
 * station says it is not available.
 */
const drivers = new Map<string, BiometricDriver>([simulatorDriver, uploadDriver].map((driver) => [driver.id, driver]));

export function registerBiometricDriver(driver: BiometricDriver): void {
    drivers.set(driver.id, driver);
}

export function biometricDriver(name: string): BiometricDriver | undefined {
    return drivers.get(name);
}

/**
 * Drivers a station may use for a kind: allowed by the organization's settings, offered by this
 * installation and present in this browser build, in the organization's order.
 */
export function driversFor(modality: string, allowed: string[], options: BiometricOptions): BiometricDriver[] {
    return allowed
        .filter((name) => options.drivers[name]?.modalities.includes(modality))
        .map((name) => drivers.get(name))
        .filter((driver): driver is BiometricDriver => driver !== undefined);
}
