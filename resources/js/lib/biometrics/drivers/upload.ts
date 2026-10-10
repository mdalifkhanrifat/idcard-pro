import { checkImageSize, detectImageFormat, formatsLabel, toBase64 } from '../files';
import { CaptureError, type BiometricDriver } from '../types';

/**
 * A file chosen at the station: a scan made elsewhere, or a photo. The format is recognized from the
 * file's first bytes, among those the kind accepts; the device and quality are unknown.
 */
export const uploadDriver: BiometricDriver = {
    id: 'upload',
    needsFile: true,
    async probe() {
        return { ready: true };
    },
    async capture({ modality, options, file }) {
        if (!file) {
            throw new CaptureError('no_file');
        }
        const bytes = new Uint8Array(await file.arrayBuffer());
        checkImageSize(bytes, options);
        const format = detectImageFormat(bytes, modality, options);
        if (format === null) {
            throw new CaptureError('format', { formats: formatsLabel(modality, options) });
        }
        let size: { width?: number; height?: number } = {};
        if (options.image_types[format].displayable) {
            try {
                const bitmap = await createImageBitmap(new Blob([bytes], { type: options.image_types[format].mime }));
                size = { width: bitmap.width, height: bitmap.height };
                bitmap.close();
            } catch {
                throw new CaptureError('unreadable', { format: format.toUpperCase() });
            }
        }

        return { imageBase64: toBase64(bytes), imageFormat: format, quality: null, ...size };
    },
};
