import { describe, expect, it } from 'vitest';
import { driversFor, hasBiometricDriver, loadBiometricDriver, registerBiometricDriver } from '..';
import { registryOptions } from './fixtures';

describe('driver registry', () => {
    it('offers a station only drivers the organization allows, the registry offers for the kind and this build has', () => {
        const options = registryOptions();
        expect(driversFor('fingerprint', ['secugen', 'zkteco', 'upload', 'webcam'], options)).toEqual(['secugen', 'upload']);
        expect(driversFor('face', ['secugen', 'bridge', 'upload'], options)).toEqual(['bridge', 'upload']);
    });

    it('loads driver modules when used, and finds nothing for a driver this build lacks', async () => {
        expect(hasBiometricDriver('bridge')).toBe(true);
        expect((await loadBiometricDriver('bridge'))?.id).toBe('bridge');
        expect(await loadBiometricDriver('zkteco')).toBeUndefined();
    });

    it('takes plug-in drivers', async () => {
        registerBiometricDriver('palmvein', async () => ({ id: 'palmvein', probe: async () => ({ ready: true }), capture: async () => ({ imageBase64: '', imageFormat: 'png', quality: null }) }));
        const options = registryOptions();
        options.drivers.palmvein = { modalities: ['fingerprint'], local_url: null, testing: false, client: {} };

        expect(driversFor('fingerprint', ['palmvein'], options)).toEqual(['palmvein']);
        expect((await loadBiometricDriver('palmvein'))?.id).toBe('palmvein');
    });
});
