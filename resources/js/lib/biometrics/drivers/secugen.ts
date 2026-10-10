import { callLocalService } from '../localService';
import { checkImageSize, formatsLabel, fromBase64, toPng } from '../files';
import { CaptureError, type BiometricDriver, type CaptureErrorCode } from '../types';

/**
 * SecuGen fingerprint scanners through the SecuGen WebAPI service (SgiBioSrv) on the station,
 * default https://localhost:8443. The service's certificate must be trusted once in the browser
 * (open the address from Station settings). Off localhost the WebAPI needs a license string for the
 * site's domain: registry `drivers.secugen.client.license` (BIOMETRIC_SECUGEN_LICENSE).
 *
 * Endpoints, fields and error codes follow SecuGen's WebAPI documentation and samples; they were
 * checked against faked answers only, so confirm them once on real hardware (PROTOCOL below).
 */
const PROTOCOL = {
    capture: '/SGIFPCapture',
    info: '/SGIFPGetInfo',
    /** Registry template formats and the WebAPI's names for them. */
    templateFormats: { 'iso-19794-2': 'ISO', 'ansi-378': 'ANSI' } as Record<string, string>,
    /** WebAPI error codes the station can explain; any other code is a device error with its number. */
    errors: { 53: 'no_device', 54: 'no_finger', 55: 'no_device', 59: 'busy', 61: 'not_supported' } as Record<number, CaptureErrorCode>,
    /** How long the scanner waits for a finger (ms); the browser waits a little longer. */
    timeout: 15_000,
};

interface SecuGenAnswer {
    ErrorCode: number;
    Manufacturer?: string;
    Model?: string;
    SerialNumber?: string;
    ImageWidth?: number;
    ImageHeight?: number;
    ImageDPI?: number;
    /** 0-100 */
    ImageQuality?: number;
    BMPBase64?: string;
    WSQImage?: string;
    TemplateBase64?: string;
}

function serviceUrl(localUrl: string | null | undefined): string {
    if (!localUrl) {
        throw new CaptureError('device');
    }

    return localUrl;
}

function failure(code: number): CaptureError {
    return new CaptureError(PROTOCOL.errors[code] ?? 'device', { message: `SecuGen ${code}` });
}

export const secugenDriver: BiometricDriver = {
    id: 'secugen',
    async probe({ options, localUrl, signal }) {
        try {
            const answer = await callLocalService<SecuGenAnswer>(serviceUrl(localUrl), PROTOCOL.info, {
                form: { licstr: String(options.drivers.secugen?.client?.license ?? '') },
                timeout: 2500,
                signal,
            });
            if (answer.ErrorCode !== 0) {
                return { ready: false, reason: failure(answer.ErrorCode).code };
            }

            return { ready: true, device: { vendor: answer.Manufacturer ?? 'SecuGen', model: answer.Model, serial: answer.SerialNumber } };
        } catch (error) {
            // An older service without the info call still answers: capture will tell more
            if (error instanceof CaptureError && error.params.status) {
                return { ready: true };
            }

            return { ready: false, reason: error instanceof CaptureError ? error.code : 'device' };
        }
    },
    async capture({ modality, options, localUrl, minQuality, signal }) {
        const kind = options.modalities[modality];
        // PNG (from the scanner's BMP, lossless and smaller), else BMP or WSQ as the kind accepts
        const imageFormat = kind.image_formats.find((format) => ['png', 'bmp', 'wsq'].includes(format));
        if (!imageFormat) {
            throw new CaptureError('format', { formats: formatsLabel(modality, options) });
        }
        const templateFormat = kind.template_formats.find((format) => PROTOCOL.templateFormats[format]);
        const answer = await callLocalService<SecuGenAnswer>(serviceUrl(localUrl), PROTOCOL.capture, {
            form: {
                Timeout: PROTOCOL.timeout,
                Quality: Math.max(1, Math.min(100, Math.round(minQuality ?? 50))),
                licstr: String(options.drivers.secugen?.client?.license ?? ''),
                ...(templateFormat ? { templateFormat: PROTOCOL.templateFormats[templateFormat] } : {}),
                ...(imageFormat === 'wsq' ? { imageWSQRate: '0.75' } : {}),
            },
            timeout: PROTOCOL.timeout + 5000,
            signal,
        });
        if (answer.ErrorCode !== 0) {
            throw failure(answer.ErrorCode);
        }
        const source = imageFormat === 'wsq' ? answer.WSQImage : answer.BMPBase64;
        if (!source) {
            throw new CaptureError('device', { message: 'SecuGen: no image' });
        }
        let imageBase64 = source;
        let size = { width: answer.ImageWidth, height: answer.ImageHeight };
        if (imageFormat === 'png') {
            const png = await toPng(fromBase64(source), 'image/bmp');
            imageBase64 = png.base64;
            size = { width: png.width, height: png.height };
        }
        checkImageSize(fromBase64(imageBase64), options);

        return {
            imageBase64,
            imageFormat,
            ...(templateFormat && answer.TemplateBase64 ? { templateBase64: answer.TemplateBase64, templateFormat } : {}),
            quality: typeof answer.ImageQuality === 'number' ? Math.max(0, Math.min(100, Math.round(answer.ImageQuality))) : null,
            ...size,
            dpi: answer.ImageDPI,
            device: { vendor: answer.Manufacturer ?? 'SecuGen', model: answer.Model, serial: answer.SerialNumber },
        };
    },
};
