import { checkImageSize, formatsLabel, fromBase64 } from '../files';
import { CaptureError, type BiometricDriver } from '../types';

/**
 * A camera on the station (getUserMedia). The station shows the live picture and captures a portrait
 * (3:4, centered) from it in the first format the kind accepts that a browser can encode. A camera
 * gives no quality score, so quality is unknown rather than made up. Browsers allow cameras only on
 * HTTPS sites (and localhost).
 */
const RATIO = 3 / 4;
const MAX_HEIGHT = 960;

/** Browser camera errors as the station explains them. */
export function cameraError(error: unknown): CaptureError {
    const name = error instanceof DOMException || error instanceof Error ? error.name : '';
    if (name === 'NotAllowedError' || name === 'SecurityError') {
        return new CaptureError('permission');
    }
    if (name === 'NotFoundError' || name === 'OverconstrainedError') {
        return new CaptureError('no_device');
    }
    if (name === 'NotReadableError' || name === 'AbortError') {
        return new CaptureError('busy');
    }

    return new CaptureError('device');
}

function mediaDevices(): MediaDevices {
    if (!globalThis.navigator?.mediaDevices?.getUserMedia) {
        throw new CaptureError('insecure');
    }

    return navigator.mediaDevices;
}

export const webcamDriver: BiometricDriver = {
    id: 'webcam',
    needsVideo: true,
    async probe() {
        try {
            const cameras = (await mediaDevices().enumerateDevices()).filter((device) => device.kind === 'videoinput');

            return cameras.length > 0 ? { ready: true } : { ready: false, reason: 'no_device' };
        } catch (error) {
            return { ready: false, reason: error instanceof CaptureError ? error.code : 'device' };
        }
    },
    async openStream({ deviceId }) {
        const devices = mediaDevices();
        try {
            return await devices.getUserMedia({
                audio: false,
                video: { ...(deviceId ? { deviceId: { exact: deviceId } } : { facingMode: 'user' }), width: { ideal: 1280 }, height: { ideal: 960 } },
            });
        } catch (error) {
            throw cameraError(error);
        }
    },
    async capture({ modality, options, video }) {
        if (!video || !video.videoWidth || !video.videoHeight) {
            throw new CaptureError('no_device');
        }
        // The largest centered 3:4 portrait in the frame
        const sourceHeight = Math.min(video.videoHeight, video.videoWidth / RATIO);
        const sourceWidth = sourceHeight * RATIO;
        const height = Math.round(Math.min(sourceHeight, MAX_HEIGHT));
        const width = Math.round(height * RATIO);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        (canvas.getContext('2d') as CanvasRenderingContext2D).drawImage(video, (video.videoWidth - sourceWidth) / 2, (video.videoHeight - sourceHeight) / 2, sourceWidth, sourceHeight, 0, 0, width, height);
        let image: { format: string; dataUrl: string } | null = null;
        for (const format of options.modalities[modality].image_formats) {
            const type = options.image_types[format];
            const dataUrl = type?.displayable ? canvas.toDataURL(type.mime, 0.92) : '';
            if (dataUrl.startsWith(`data:${type?.mime};`)) {
                image = { format, dataUrl };
                break;
            }
        }
        if (image === null) {
            throw new CaptureError('format', { formats: formatsLabel(modality, options) });
        }
        const imageBase64 = image.dataUrl.slice(image.dataUrl.indexOf(',') + 1);
        checkImageSize(fromBase64(imageBase64), options);
        const track = (video.srcObject as MediaStream | null)?.getVideoTracks()[0];

        return { imageBase64, imageFormat: image.format, quality: null, width, height, device: track?.label ? { model: track.label } : undefined };
    },
};
