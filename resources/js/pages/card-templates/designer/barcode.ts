import JsBarcode from 'jsbarcode';

/** Our format keys (config `barcode_formats`) and the JsBarcode symbology each one uses. */
export const BARCODE_FORMATS = {
    code128: 'CODE128',
    code39: 'CODE39',
    ean13: 'EAN13',
    ean8: 'EAN8',
    upc: 'UPC',
    itf: 'ITF',
    codabar: 'codabar',
} as const;

export type BarcodeFormat = keyof typeof BARCODE_FORMATS;

/** Names shown in the format list; symbology names are the same in every language. */
export const BARCODE_FORMAT_NAMES: Record<BarcodeFormat, string> = {
    code128: 'Code 128',
    code39: 'Code 39',
    ean13: 'EAN-13',
    ean8: 'EAN-8',
    upc: 'UPC-A',
    itf: 'ITF (Interleaved 2 of 5)',
    codabar: 'Codabar',
};

/** Below this narrowest-bar width (mm) many card scanners struggle. */
export const MIN_MODULE_MM = 0.2;

/** Light margin left and right of the bars, in modules (narrowest bar widths); scanners need about ten. */
export const QUIET_ZONE_MODULES = 10;

export interface EncodedBarcode {
    /** Dark bars as [first module, width in modules]. */
    bars: [number, number][];
    /** Width of the bars in modules, without the quiet zone. */
    modules: number;
    /** Human-readable text; EAN and UPC include the check digit. */
    text: string;
}

/**
 * Encode a value with a tested library (JsBarcode) rather than our own tables: one wrong pattern
 * makes a card unscannable. Returns null when the format cannot encode the value.
 */
export function encodeBarcode(value: string, format: BarcodeFormat = 'code128'): EncodedBarcode | null {
    if (value === '') {
        return null;
    }
    let valid = true;
    const target: { encodings?: { data: string; text: string }[] } = {};
    try {
        JsBarcode(target, value, {
            format: BARCODE_FORMATS[format] ?? BARCODE_FORMATS.code128,
            flat: true,
            valid: (ok: boolean) => {
                valid = ok;
            },
        });
    } catch {
        return null;
    }
    if (!valid || !target.encodings?.length) {
        return null;
    }

    const binary = target.encodings.map((encoding) => encoding.data).join('');
    const bars: [number, number][] = [];
    for (let module = 0; module < binary.length; module++) {
        if (binary[module] !== '1') {
            continue;
        }
        const last = bars[bars.length - 1];
        if (last && last[0] + last[1] === module) {
            last[1]++;
        } else {
            bars.push([module, 1]);
        }
    }

    return { bars, modules: binary.length, text: target.encodings.map((encoding) => encoding.text).join('') };
}
