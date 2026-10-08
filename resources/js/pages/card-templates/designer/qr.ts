import qrcode from 'qrcode-generator';
import type { QrLine } from '@/types';

/** Error correction levels (config `qr_error_levels`): how much of the code may be damaged and still scan. */
export const QR_ERROR_LEVELS = ['L', 'M', 'Q', 'H'] as const;
export type QrErrorLevel = (typeof QR_ERROR_LEVELS)[number];

/** The legacy cards used the highest level, which also survives scratches on a worn card. */
export const DEFAULT_QR_ERROR_LEVEL: QrErrorLevel = 'H';

/** Most UTF-8 bytes the largest QR code (version 40) holds at each level; more text cannot be encoded. */
export const QR_MAX_BYTES: Record<QrErrorLevel, number> = { L: 2953, M: 2331, Q: 1663, H: 1273 };

/** Light margin around the code, in modules; the QR standard asks for four. */
export const QR_QUIET_ZONE = 4;

/** Below this module size (mm) phone cameras struggle with a printed code. */
export const MIN_QR_MODULE_MM = 0.3;

export interface EncodedQr {
    /** Modules per side, without the quiet zone. */
    size: number;
    /** SVG path of the dark modules, one unit per module. */
    path: string;
}

/**
 * The text a QR element encodes (legacy qrcode_info): one line per entry, a field as "Label:value" (or the
 * value alone), fixed text as written; fields without a value are left out, as on the legacy cards.
 */
export function qrText(lines: QrLine[] | null, valueOf: (field: string) => string): string {
    return (lines ?? [])
        .map((line) => {
            if (line.text !== undefined) {
                return line.text.trim();
            }
            const value = line.field ? valueOf(line.field).trim() : '';

            return value === '' ? '' : line.label ? `${line.label}:${value}` : value;
        })
        .filter((line) => line !== '')
        .join('\n');
}

/**
 * Encode text with a tested library (qrcode-generator) at the smallest size that holds it. The text is sent
 * as UTF-8 bytes so Bangla and other scripts scan correctly (the library alone keeps only Latin-1).
 * Returns null for empty text or text too long for any QR size at this level.
 */
export function encodeQr(text: string, level: QrErrorLevel = DEFAULT_QR_ERROR_LEVEL): EncodedQr | null {
    if (text === '') {
        return null;
    }
    const bytes = new TextEncoder().encode(text);
    let binary = '';
    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }
    try {
        const qr = qrcode(0, level);
        qr.addData(binary, 'Byte');
        qr.make();
        const size = qr.getModuleCount();
        let path = '';
        for (let row = 0; row < size; row++) {
            for (let col = 0; col < size; col++) {
                if (!qr.isDark(row, col)) {
                    continue;
                }
                // One rectangle per run of dark modules keeps the drawing small
                let run = 1;
                while (col + run < size && qr.isDark(row, col + run)) {
                    run++;
                }
                path += `M${col} ${row}h${run}v1h-${run}z`;
                col += run - 1;
            }
        }

        return { size, path };
    } catch {
        return null;
    }
}
