import { beforeEach, describe, expect, it } from 'vitest';
import { isLoopbackUrl, setStationUrl, stationUrl } from '../station';
import { memoryStorage, registryOptions } from './fixtures';

describe('station addresses', () => {
    let storage: Storage;
    beforeEach(() => {
        storage = memoryStorage();
    });

    it('accepts only addresses on this computer', () => {
        for (const url of ['http://127.0.0.1:47800', 'https://localhost:8443', 'http://[::1]:9000', 'http://127.1.2.3']) {
            expect(isLoopbackUrl(url), url).toBe(true);
        }
        for (const url of ['http://192.168.1.5:47800', 'https://example.com', 'ftp://localhost', 'localhost:8443', 'http://localhost.evil.com', 'http://127.0.0.1.evil.com', '']) {
            expect(isLoopbackUrl(url), url).toBe(false);
        }
    });

    it('uses the registry default until the station saves its own address', () => {
        const options = registryOptions();
        expect(stationUrl('bridge', options)).toBe('http://127.0.0.1:47800');
        expect(stationUrl('upload', options)).toBeNull();

        setStationUrl('bridge', 'http://localhost:9999/');
        expect(stationUrl('bridge', options)).toBe('http://localhost:9999');

        setStationUrl('bridge', null);
        expect(stationUrl('bridge', options)).toBe('http://127.0.0.1:47800');
    });

    it('never saves or uses an address on another machine, even one written into storage', () => {
        const options = registryOptions();
        setStationUrl('bridge', 'http://10.0.0.5:47800');
        expect(storage.getItem('biometric-station:bridge:url')).toBeNull();

        storage.setItem('biometric-station:bridge:url', 'https://evil.example');
        expect(stationUrl('bridge', options)).toBe('http://127.0.0.1:47800');
    });
});
