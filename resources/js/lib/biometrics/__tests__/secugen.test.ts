import { describe, expect, it, vi } from 'vitest';
import { CaptureError } from '../types';
import { fakeFetch, json, PNG_BASE64, registryOptions } from './fixtures';

// Decoding a BMP needs a browser canvas: the conversion itself is checked in the browser QA
vi.mock('../files', async (original) => ({ ...(await original<typeof import('../files')>()), toPng: vi.fn(async () => ({ base64: PNG_BASE64, width: 64, height: 80 })) }));
const { secugenDriver } = await import('../drivers/secugen');

const localUrl = 'https://localhost:8443';
const request = { modality: 'fingerprint', position: 'right_thumb', options: registryOptions(), localUrl, minQuality: 40 };
const answer = { ErrorCode: 0, Manufacturer: 'SecuGen', Model: 'Hamster Pro 20', SerialNumber: 'H582', ImageWidth: 64, ImageHeight: 80, ImageDPI: 500, ImageQuality: 85, BMPBase64: 'Qk0AAA==', WSQImage: '/6D/qA==', TemplateBase64: 'Rk1S' };

async function failure(promise: Promise<unknown>): Promise<CaptureError> {
    const error = await promise.then(() => null, (caught: unknown) => caught);
    expect(error).toBeInstanceOf(CaptureError);

    return error as CaptureError;
}

describe('SecuGen driver', () => {
    it('captures with the minimum quality, the license string and the ISO template, and returns a PNG', async () => {
        const calls = fakeFetch(() => json(answer));

        const result = await secugenDriver.capture(request);

        expect(calls[0].url).toBe('https://localhost:8443/SGIFPCapture');
        expect(Object.fromEntries(calls[0].init.body as URLSearchParams)).toEqual({ Timeout: '15000', Quality: '40', licstr: 'LIC', templateFormat: 'ISO' });
        expect(result).toEqual({
            imageBase64: PNG_BASE64, imageFormat: 'png', templateBase64: 'Rk1S', templateFormat: 'iso-19794-2', quality: 85,
            width: 64, height: 80, dpi: 500, device: { vendor: 'SecuGen', model: 'Hamster Pro 20', serial: 'H582' },
        });
    });

    it('sends WSQ when that is what the kind accepts', async () => {
        const options = registryOptions();
        options.modalities.fingerprint.image_formats = ['wsq'];
        options.modalities.fingerprint.template_formats = ['ansi-378'];
        const calls = fakeFetch(() => json(answer));

        const result = await secugenDriver.capture({ ...request, options });

        expect(Object.fromEntries(calls[0].init.body as URLSearchParams)).toMatchObject({ templateFormat: 'ANSI', imageWSQRate: '0.75' });
        expect(result).toMatchObject({ imageBase64: '/6D/qA==', imageFormat: 'wsq', templateFormat: 'ansi-378' });
    });

    it('explains WebAPI error codes', async () => {
        for (const [code, expected] of [[54, 'no_finger'], [59, 'busy'], [53, 'no_device'], [61, 'not_supported'], [99, 'device']] as const) {
            fakeFetch(() => json({ ErrorCode: code }));
            const error = await failure(secugenDriver.capture(request));
            expect(error.code, String(code)).toBe(expected);
            expect(error.params.message).toBe(`SecuGen ${code}`);
        }
    });

    it('reports the device, a missing scanner, and an older service without the info call', async () => {
        fakeFetch(() => json({ ErrorCode: 0, Manufacturer: 'SecuGen', Model: 'Hamster Pro 20', SerialNumber: 'H582' }));
        expect(await secugenDriver.probe(request)).toEqual({ ready: true, device: { vendor: 'SecuGen', model: 'Hamster Pro 20', serial: 'H582' } });

        fakeFetch(() => json({ ErrorCode: 55 }));
        expect(await secugenDriver.probe(request)).toEqual({ ready: false, reason: 'no_device' });

        fakeFetch(() => new Response('Not found', { status: 404 }));
        expect(await secugenDriver.probe(request)).toEqual({ ready: true });

        fakeFetch(() => Promise.reject(new TypeError('Failed to fetch')));
        expect(await secugenDriver.probe(request)).toEqual({ ready: false, reason: 'device' });
    });
});
