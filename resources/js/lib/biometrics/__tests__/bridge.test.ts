import { describe, expect, it } from 'vitest';
import { bridgeDriver } from '../drivers/bridge';
import { CaptureError } from '../types';
import { fakeFetch, hanging, json, PNG_BASE64, registryOptions } from './fixtures';

const localUrl = 'http://127.0.0.1:47800';
const device = { id: 'usb-1', vendor: 'ZKTeco', model: 'SLK20R', serial: 'ABC', modalities: ['fingerprint'], ready: true };
const request = { modality: 'fingerprint', position: 'right_thumb', options: registryOptions(), localUrl, minQuality: 40 };

async function failure(promise: Promise<unknown>): Promise<CaptureError> {
    const error = await promise.then(() => null, (caught: unknown) => caught);
    expect(error).toBeInstanceOf(CaptureError);

    return error as CaptureError;
}

describe('bridge driver', () => {
    it('is ready when a ready device supports the kind', async () => {
        fakeFetch(() => json({ protocol: 1, devices: [device] }));
        expect(await bridgeDriver.probe(request)).toEqual({ ready: true, device: { vendor: 'ZKTeco', model: 'SLK20R', serial: 'ABC' } });
        expect(await bridgeDriver.probe({ ...request, modality: 'face' })).toEqual({ ready: false, reason: 'no_device' });

        fakeFetch(() => json({ protocol: 1, devices: [{ ...device, ready: false }] }));
        expect(await bridgeDriver.probe(request)).toEqual({ ready: false, reason: 'no_device' });

        fakeFetch(() => json({ protocol: 2, devices: [device] }));
        expect(await bridgeDriver.probe(request)).toEqual({ ready: false, reason: 'outdated' });

        fakeFetch(() => Promise.reject(new TypeError('Failed to fetch')));
        expect(await bridgeDriver.probe(request)).toEqual({ ready: false, reason: 'device' });
    });

    it('asks for the kind, position, accepted formats and minimum quality, and returns what the API takes', async () => {
        const calls = fakeFetch(() =>
            json({ ok: true, image: { format: 'png', base64: PNG_BASE64, width: 300, height: 400, dpi: 500 }, template: { format: 'iso-19794-2', base64: 'Rk1S' }, quality: 72.6, device: { vendor: 'ZKTeco' } }),
        );

        const result = await bridgeDriver.capture(request);

        expect(JSON.parse(String(calls[0].init.body))).toEqual({
            modality: 'fingerprint', position: 'right_thumb', image_formats: ['png', 'jpeg', 'bmp', 'wsq'],
            template_formats: ['iso-19794-2', 'ansi-378', 'iso-19794-2-2011'], min_quality: 40, timeout_ms: 30000,
        });
        expect(result).toEqual({ imageBase64: PNG_BASE64, imageFormat: 'png', templateBase64: 'Rk1S', templateFormat: 'iso-19794-2', quality: 73, width: 300, height: 400, dpi: 500, device: { vendor: 'ZKTeco' } });
    });

    it('drops a template in a format the kind does not take, and clamps quality', async () => {
        fakeFetch(() => json({ ok: true, image: { format: 'png', base64: PNG_BASE64 }, template: { format: 'vendor-x', base64: 'AAAA' }, quality: 140 }));

        const result = await bridgeDriver.capture(request);

        expect(result.templateBase64).toBeUndefined();
        expect(result.quality).toBe(100);
    });

    it('explains the errors the bridge reports, and refuses images in other formats', async () => {
        fakeFetch(() => json({ ok: false, error: 'no_finger', message: 'No finger' }));
        expect((await failure(bridgeDriver.capture(request))).code).toBe('no_finger');

        fakeFetch(() => json({ ok: false, error: 'laser_overheat' }));
        expect((await failure(bridgeDriver.capture(request))).code).toBe('device');

        fakeFetch(() => json({ ok: true, image: { format: 'gif', base64: PNG_BASE64 }, quality: 80 }));
        expect((await failure(bridgeDriver.capture(request))).code).toBe('format');
    });

    it('tells the bridge to stop waiting when the station cancels', async () => {
        const calls = fakeFetch((path, init) => (path === '/v1/capture' ? hanging(init) : json({ ok: true })));
        const cancel = new AbortController();

        const waiting = bridgeDriver.capture({ ...request, signal: cancel.signal });
        cancel.abort();

        expect((await failure(waiting)).code).toBe('cancelled');
        expect(calls.map((call) => new URL(call.url).pathname)).toEqual(['/v1/capture', '/v1/cancel']);
    });
});
