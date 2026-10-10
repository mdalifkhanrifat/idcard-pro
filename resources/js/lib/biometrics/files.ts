import type { BiometricOptions } from '@/types';
import { CaptureError } from './types';

/** Bytes as base64 (no data URL prefix), in chunks so large images do not overflow the call stack. */
export function toBase64(bytes: Uint8Array): string {
    let binary = '';
    for (let offset = 0; offset < bytes.length; offset += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
    }

    return btoa(binary);
}

/** The image format of the bytes among those a modality accepts, by their first bytes (as the server checks). */
export function detectImageFormat(bytes: Uint8Array, modality: string, options: BiometricOptions): string | null {
    const head = Array.from(bytes.subarray(0, 8), (byte) => byte.toString(16).padStart(2, '0')).join('');

    return options.modalities[modality].image_formats.find((format) => head.startsWith(options.image_types[format]?.signature ?? '\u0000')) ?? null;
}

/** For a file input: content types of formats a browser shows, file endings of the others (e.g. WSQ). */
export function acceptFor(modality: string, options: BiometricOptions): string {
    return options.modalities[modality].image_formats
        .flatMap((format) => (options.image_types[format]?.displayable ? [options.image_types[format].mime] : [`.${format}`]))
        .join(',');
}

/** Refuse what the server would refuse, before sending it. */
export function checkImageSize(bytes: Uint8Array, options: BiometricOptions): void {
    if (bytes.length > options.max_image_kb * 1024) {
        throw new CaptureError('too_large', { kb: options.max_image_kb });
    }
}

export function formatsLabel(modality: string, options: BiometricOptions): string {
    return options.modalities[modality].image_formats.map((format) => format.toUpperCase()).join(', ');
}
