import { describe, expect, it } from 'vitest';
import { acceptFor, checkImageSize, detectImageFormat, fromBase64, toBase64 } from '../files';
import { CaptureError } from '../types';
import { registryOptions } from './fixtures';

const bytes = (...values: number[]) => new Uint8Array(values);

describe('image files', () => {
    it('recognizes a format by its first bytes among those the kind accepts', () => {
        const options = registryOptions();
        expect(detectImageFormat(bytes(0x89, 0x50, 0x4e, 0x47, 0x0d), 'fingerprint', options)).toBe('png');
        expect(detectImageFormat(bytes(0xff, 0xd8, 0xff, 0xe0), 'face', options)).toBe('jpeg');
        expect(detectImageFormat(bytes(0x42, 0x4d, 0, 0), 'fingerprint', options)).toBe('bmp');
        expect(detectImageFormat(bytes(0xff, 0xa0, 0xff, 0xa8), 'fingerprint', options)).toBe('wsq');
        // A face is never BMP or WSQ; unknown bytes are nothing
        expect(detectImageFormat(bytes(0x42, 0x4d, 0, 0), 'face', options)).toBeNull();
        expect(detectImageFormat(bytes(1, 2, 3, 4), 'fingerprint', options)).toBeNull();
    });

    it('offers content types of formats a browser shows and file endings of the others', () => {
        const options = registryOptions();
        expect(acceptFor('fingerprint', options)).toBe('image/png,image/jpeg,image/bmp,.wsq');
        expect(acceptFor('face', options)).toBe('image/jpeg,image/png');
    });

    it('refuses images over the size limit, as the server does', () => {
        const options = { ...registryOptions(), max_image_kb: 1 };
        expect(() => checkImageSize(new Uint8Array(1024), options)).not.toThrow();
        expect(() => checkImageSize(new Uint8Array(1025), options)).toThrow(CaptureError);
    });

    it('turns large byte arrays into base64 and back', () => {
        const data = Uint8Array.from({ length: 200_000 }, (_, index) => index % 256);
        expect(fromBase64(toBase64(data))).toEqual(data);
    });
});
