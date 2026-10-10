import { checkImageSize, formatsLabel, toBase64 } from '../files';
import { CaptureError, type BiometricDriver } from '../types';

const WIDTH = 320;
const HEIGHT = 400;

/** Small seeded random numbers, so one capture's drawing is consistent. */
function seeded(seed: number): () => number {
    let state = seed >>> 0;

    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let value = Math.imul(state ^ (state >>> 15), 1 | state);
        value ^= value + Math.imul(value ^ (value >>> 7), 61 | value);

        return ((value ^ (value >>> 14)) >>> 0) / 4294967296;
    };
}

function wait(ms: number, signal?: AbortSignal): Promise<void> {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(resolve, ms);
        signal?.addEventListener('abort', () => {
            clearTimeout(timer);
            reject(new CaptureError('cancelled'));
        }, { once: true });
    });
}

/** A made-up ridge pattern, marked as simulated so it is never taken for a real capture. */
function draw(position: string, random: () => number): HTMLCanvasElement {
    const canvas = document.createElement('canvas');
    canvas.width = WIDTH;
    canvas.height = HEIGHT;
    const context = canvas.getContext('2d') as CanvasRenderingContext2D;
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, WIDTH, HEIGHT);
    const centerX = WIDTH / 2 + (random() - 0.5) * 60;
    const centerY = HEIGHT / 2 + (random() - 0.5) * 80;
    const waves = 3 + Math.floor(random() * 4);
    const phase = random() * Math.PI * 2;
    context.strokeStyle = '#2b2b2b';
    context.lineWidth = 3.2;
    for (let ring = 1; ring < 34; ring++) {
        context.beginPath();
        for (let step = 0; step <= 120; step++) {
            const angle = (step / 120) * Math.PI * 2;
            const radius = ring * 7.5 + Math.sin(angle * waves + phase + ring * 0.15) * 4;
            const x = centerX + Math.cos(angle) * radius * 0.8;
            const y = centerY + Math.sin(angle) * radius;
            if (step === 0) {
                context.moveTo(x, y);
            } else {
                context.lineTo(x, y);
            }
        }
        context.stroke();
    }
    context.fillStyle = 'rgba(220, 38, 38, 0.85)';
    context.fillRect(0, HEIGHT - 28, WIDTH, 28);
    context.fillStyle = '#ffffff';
    context.font = 'bold 13px sans-serif';
    context.fillText(`SIMULATED · ${position}`, 10, HEIGHT - 10);

    return canvas;
}

/**
 * Testing only (never offered in production): draws an image for any kind and position, with a
 * random quality from 20 to 100 so the "try again" and "keep anyway" paths can be tried, and a
 * made-up template when the kind has template formats.
 */
export const simulatorDriver: BiometricDriver = {
    id: 'simulator',
    async probe() {
        return { ready: true, device: { vendor: 'IDCard Pro', model: 'Simulator' } };
    },
    async capture({ modality, position, options, signal }) {
        await wait(300, signal);
        const random = seeded(Date.now() ^ Math.floor(Math.random() * 0xffffffff));
        const canvas = draw(position, random);
        // The first format of the kind that the browser can draw to
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
        checkImageSize(Uint8Array.from(atob(imageBase64), (char) => char.charCodeAt(0)), options);
        const templateFormat = options.modalities[modality].template_formats[0];
        let template: { templateBase64: string; templateFormat: string } | object = {};
        if (templateFormat) {
            const bytes = new Uint8Array(256);
            crypto.getRandomValues(bytes);
            bytes.set(new TextEncoder().encode('SIMULATED-TEMPLATE'));
            template = { templateBase64: toBase64(bytes), templateFormat };
        }

        return {
            imageBase64,
            imageFormat: image.format,
            ...template,
            quality: 20 + Math.floor(random() * 81),
            width: WIDTH,
            height: HEIGHT,
            dpi: 500,
            device: { vendor: 'IDCard Pro', model: 'Simulator', serial: 'SIM-0001' },
        };
    },
};
