import { callLocalService } from '../localService';
import { checkImageSize, formatsLabel } from '../files';
import { CaptureError, type BiometricDevice, type BiometricDriver, type CaptureErrorCode } from '../types';

/**
 * Our own capture agent on the station's computer (docs/BIOMETRIC_BRIDGE.md, protocol 1). It wraps
 * any vendor's SDK, so a scanner brand without a browser service is connected by a bridge build,
 * not by changing this application.
 */
interface BridgeDevice extends BiometricDevice {
    id: string;
    modalities: string[];
    ready: boolean;
}

interface BridgeInfo {
    protocol: number;
    name?: string;
    version?: string;
    devices: BridgeDevice[];
}

type BridgeCapture =
    | {
          ok: true;
          image: { format: string; base64: string; width?: number; height?: number; dpi?: number };
          template?: { format: string; base64: string } | null;
          quality: number | null;
          device?: BiometricDevice;
      }
    | { ok: false; error: string; message?: string };

const PROTOCOL = 1;
/** Errors the bridge may report, as the station explains them. */
const BRIDGE_ERRORS: CaptureErrorCode[] = ['no_finger', 'timeout', 'busy', 'device', 'not_supported', 'no_device', 'cancelled'];
/** A scanner waits for the finger; the bridge gives up first (timeout_ms), this is the last resort. */
const CAPTURE_TIMEOUT = 35_000;

function baseUrl(localUrl: string | null | undefined): string {
    if (!localUrl) {
        throw new CaptureError('device');
    }

    return localUrl;
}

export const bridgeDriver: BiometricDriver = {
    id: 'bridge',
    async probe({ modality, localUrl, signal }) {
        try {
            const info = await callLocalService<BridgeInfo>(baseUrl(localUrl), '/v1/info', { method: 'GET', timeout: 2500, signal });
            if (info.protocol !== PROTOCOL) {
                return { ready: false, reason: 'not_supported' };
            }
            const device = (info.devices ?? []).find((item) => item.ready && item.modalities?.includes(modality));

            return device ? { ready: true, device: { vendor: device.vendor, model: device.model, serial: device.serial } } : { ready: false, reason: 'no_device' };
        } catch (error) {
            return { ready: false, reason: error instanceof CaptureError ? error.code : 'device' };
        }
    },
    async capture({ modality, position, options, localUrl, signal }) {
        const url = baseUrl(localUrl);
        const kind = options.modalities[modality];
        const settings = { image_formats: kind.image_formats, template_formats: kind.template_formats };
        // Tell the bridge to stop waiting for the finger when the station cancels
        const cancel = () => void callLocalService(url, '/v1/cancel', { json: {}, timeout: 2000 }).catch(() => undefined);
        signal?.addEventListener('abort', cancel, { once: true });
        let answer: BridgeCapture;
        try {
            answer = await callLocalService<BridgeCapture>(url, '/v1/capture', {
                json: { modality, position, ...settings, timeout_ms: 30_000 },
                timeout: CAPTURE_TIMEOUT,
                signal,
            });
        } finally {
            signal?.removeEventListener('abort', cancel);
        }
        if (!answer.ok) {
            const code = BRIDGE_ERRORS.find((item) => item === answer.error) ?? 'device';
            throw new CaptureError(code, answer.message ? { message: answer.message } : {});
        }
        if (!kind.image_formats.includes(answer.image?.format) || typeof answer.image.base64 !== 'string') {
            throw new CaptureError('format', { formats: formatsLabel(modality, options) });
        }
        checkImageSize(Uint8Array.from(atob(answer.image.base64), (char) => char.charCodeAt(0)), options);
        const template = answer.template && kind.template_formats.includes(answer.template.format) ? { templateBase64: answer.template.base64, templateFormat: answer.template.format } : {};

        return {
            imageBase64: answer.image.base64,
            imageFormat: answer.image.format,
            ...template,
            quality: typeof answer.quality === 'number' ? Math.max(0, Math.min(100, Math.round(answer.quality))) : null,
            width: answer.image.width,
            height: answer.image.height,
            dpi: answer.image.dpi,
            device: answer.device,
        };
    },
};
