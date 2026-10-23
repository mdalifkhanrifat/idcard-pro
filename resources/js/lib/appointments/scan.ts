/**
 * Reading an appointment's QR code with the station's camera (5.5e): the browser's own BarcodeDetector where it
 * has one (Chrome, Edge, Android), else jsQR (loaded only then; it decodes in the page — nothing leaves it).
 * Cameras work only on HTTPS sites and localhost.
 */

/** Why the camera cannot be used, as the page explains it. */
export type CameraProblem = 'insecure' | 'permission' | 'no_device' | 'busy' | 'device';

/** Whether this page may ask for a camera at all (a secure page with media devices). */
export function cameraAvailable(): boolean {
    return !!globalThis.navigator?.mediaDevices?.getUserMedia;
}

/** A browser camera error as one of the problems the page names. */
export function cameraProblem(error: unknown): CameraProblem {
    const name = error instanceof DOMException || error instanceof Error ? error.name : '';
    if (name === 'InsecureError') {
        return 'insecure';
    }
    if (name === 'NotAllowedError' || name === 'SecurityError') {
        return 'permission';
    }
    if (name === 'NotFoundError' || name === 'OverconstrainedError') {
        return 'no_device';
    }
    if (name === 'NotReadableError' || name === 'AbortError') {
        return 'busy';
    }

    return 'device';
}

/** The back camera where there is one (a phone), without sound. */
export async function openCamera(): Promise<MediaStream> {
    if (!cameraAvailable()) {
        throw Object.assign(new Error('insecure'), { name: 'InsecureError' });
    }

    return navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' } } });
}

/**
 * An appointment's number from what a QR code holds: the number itself, or a link naming it (`?number=` or its
 * last path part) — so a code printed in a message (5.7) may carry a link later. Null when nothing is in it.
 */
export function numberFromQr(text: string): string | null {
    const value = text.trim();
    if (value === '') {
        return null;
    }
    if (/^https?:\/\//i.test(value)) {
        try {
            const url = new URL(value);
            const named = url.searchParams.get('number') ?? url.pathname.split('/').filter(Boolean).at(-1) ?? '';

            return decodeURIComponent(named).trim() || null;
        } catch {
            return null;
        }
    }

    return value;
}

/** Reads a QR code in a video frame: its text, or null when none is seen. */
export type QrReader = (video: HTMLVideoElement) => Promise<string | null>;

interface NativeDetector {
    detect(source: HTMLVideoElement): Promise<{ rawValue: string }[]>;
}

/** The browser's own detector when it reads QR codes, else jsQR on a canvas copy of the frame. */
export async function qrReader(): Promise<QrReader> {
    const Detector = (globalThis as { BarcodeDetector?: { new (options: { formats: string[] }): NativeDetector; getSupportedFormats?: () => Promise<string[]> } }).BarcodeDetector;
    const formats: string[] = (await Detector?.getSupportedFormats?.().catch(() => [])) ?? [];
    if (Detector && formats.includes('qr_code')) {
        const detector = new Detector({ formats: ['qr_code'] });

        return async (video) => (await detector.detect(video).catch(() => []))[0]?.rawValue ?? null;
    }
    const { default: jsQR } = await import('jsqr');
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    return async (video) => {
        if (!context || !video.videoWidth) {
            return null;
        }
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        context.drawImage(video, 0, 0);
        const frame = context.getImageData(0, 0, canvas.width, canvas.height);

        return jsQR(frame.data, frame.width, frame.height, { inversionAttempts: 'dontInvert' })?.data ?? null;
    };
}
