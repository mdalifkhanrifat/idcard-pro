import type { BiometricOptions } from '@/types';

/**
 * Browser side of capture (Phase 4). A driver talks to one kind of device (a scanner's local service,
 * the webcam, a file, the simulator) and hands back what the enrollment API takes. Which kinds a driver
 * may capture comes from the registry (`biometric_options.drivers`), not from the driver.
 */
export interface BiometricDevice {
    vendor?: string;
    model?: string;
    serial?: string;
}

export interface CaptureRequest {
    modality: string;
    position: string;
    /** The registry as the API sends it (formats, image types, limits). */
    options: BiometricOptions;
    /** For drivers that need one (`needsFile`). */
    file?: File | null;
    /** The station's address of the vendor's local service, if it differs from the registry's. */
    localUrl?: string | null;
    signal?: AbortSignal;
}

/** Field names follow the enrollment API (`POST /cardholders/{id}/biometrics`) in camelCase. */
export interface CaptureResult {
    imageBase64: string;
    imageFormat: string;
    templateBase64?: string;
    templateFormat?: string;
    /** 0-100; null when the device does not tell. */
    quality: number | null;
    width?: number;
    height?: number;
    dpi?: number;
    device?: BiometricDevice;
}

export interface ProbeResult {
    ready: boolean;
    /** Why not ready: a CaptureErrorCode. */
    reason?: CaptureErrorCode;
    device?: BiometricDevice;
}

export interface BiometricDriver {
    /** The driver's key in config/biometrics.php. */
    id: string;
    /** The station picks a file before capturing. */
    needsFile?: boolean;
    /** Whether the device can be used now (e.g. the vendor's service answers). */
    probe(request: Pick<CaptureRequest, 'options' | 'localUrl' | 'signal'>): Promise<ProbeResult>;
    capture(request: CaptureRequest): Promise<CaptureResult>;
}

/** Shown as `biometrics.errors.<code>` with `params`. */
export type CaptureErrorCode = 'no_file' | 'format' | 'too_large' | 'unreadable' | 'not_available' | 'cancelled' | 'device';

export class CaptureError extends Error {
    constructor(
        public readonly code: CaptureErrorCode,
        public readonly params: Record<string, string | number> = {},
    ) {
        super(code);
        this.name = 'CaptureError';
    }
}
