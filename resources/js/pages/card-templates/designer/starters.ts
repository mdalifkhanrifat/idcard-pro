import { LAYOUT_FORMAT, type LayoutElement, type LayoutFile } from './transfer';

/**
 * Ready-made designs to start from. They use real data fields, so they print correctly as they are,
 * and are scaled to the template's card size when applied.
 */
const NAVY = '#0b3d91';
const INK = '#111827';
const MUTED = '#4b5563';

const field = (field: string, x: number, y: number, width: number, height: number, style: LayoutElement['style'] = {}, label: string | null = null, side: 'front' | 'back' = 'front'): LayoutElement => ({
    side, type: 'field', field, label, content: null, qr_lines: null, x, y, width, height, style: { font_family: 'Inter', font_size: 8, color: INK, ...style },
});
const text = (content: string, x: number, y: number, width: number, height: number, style: LayoutElement['style'] = {}, side: 'front' | 'back' = 'front'): LayoutElement => ({
    side, type: 'text', field: null, label: null, content, qr_lines: null, x, y, width, height, style: { font_family: 'Inter', font_size: 7, color: INK, ...style },
});
const box = (type: LayoutElement['type'], x: number, y: number, width: number, height: number, style: LayoutElement['style'] = {}, side: 'front' | 'back' = 'front', extra: Partial<LayoutElement> = {}): LayoutElement => ({
    side, type, field: null, label: null, content: null, qr_lines: null, x, y, width, height, style, ...extra,
});
const qr = (x: number, y: number, size: number, side: 'front' | 'back' = 'back') => box('qr_code', x, y, size, size, {}, side, { qr_lines: [{ field: 'employee_no', label: 'ID' }, { field: 'full_name' }] });

const landscape = { width: 85.6, height: 54 };
const portrait = { width: 54, height: 85.6 };

const backSide = (size: { width: number; height: number }): LayoutElement[] => [
    box('background', 0, 0, size.width, size.height, { background: '#ffffff' }, 'back'),
    box('shape', 0, 0, size.width, 3, { background: NAVY }, 'back'),
    text('If found, please return to:', 5, 8, size.width - 10, 4, { font_size: 7, color: MUTED }, 'back'),
    field('organization.name', 5, 12.5, size.width - 10, 5, { font_size: 9, bold: true, auto_fit: true, single_line: true }, null, 'back'),
    qr(size.width / 2 - 11, size.height - 32, 22),
    field('card.number', 5, size.height - 8, size.width - 10, 4, { font_size: 7, align: 'center', color: MUTED }, 'Card no', 'back'),
];

export const STARTERS: { key: string; file: LayoutFile }[] = [
    {
        key: 'band',
        file: {
            format: LAYOUT_FORMAT,
            version: 1,
            size_mm: landscape,
            elements: [
                box('background', 0, 0, 85.6, 54, { background: '#ffffff', gradient_to: '#e0ecff', gradient_direction: 'to bottom' }),
                box('shape', 0, 0, 85.6, 11, { background: NAVY }),
                field('organization.name', 3, 0, 79.6, 11, { color: '#ffffff', font_size: 11, bold: true, uppercase: true, letter_spacing: 1, align: 'center', vertical_align: 'middle', auto_fit: true, single_line: true }),
                box('photo', 4, 15, 22, 27, { border_width: 0.5, border_color: NAVY, border_radius: 1.5 }),
                field('full_name', 30, 15, 52, 6.5, { font_size: 12, bold: true, auto_fit: true, single_line: true }),
                field('designation', 30, 22, 52, 5, { font_size: 8.5, color: MUTED, single_line: true, auto_fit: true }),
                box('shape', 30, 28, 30, 0.3, { background: NAVY }),
                field('employee_no', 30, 30, 52, 4.5, { font_size: 8, label_color: NAVY, bold: true }, 'ID'),
                field('card.expiry_date', 30, 35, 52, 4.5, { font_size: 7.5, label_color: NAVY }, 'Valid till'),
                box('signatory_signature', 60, 41, 22, 7),
                text('Authorized signature', 58, 48.5, 26, 3.5, { font_size: 5.5, align: 'center', color: MUTED }),
                ...backSide(landscape),
            ],
        },
    },
    {
        key: 'stripe',
        file: {
            format: LAYOUT_FORMAT,
            version: 1,
            size_mm: landscape,
            elements: [
                box('background', 0, 0, 85.6, 54, { background: '#ffffff' }),
                box('shape', 0, 0, 11, 54, { background: '#0f766e', gradient_to: '#115e59', gradient_direction: 'to bottom' }),
                field('organization.name', 0, 3, 11, 48, { color: '#ffffff', font_size: 9, bold: true, uppercase: true, letter_spacing: 2, align: 'center', vertical_align: 'middle', rotation: 270, auto_fit: true, single_line: true }),
                box('logo', 70, 4, 11, 11, {}),
                box('photo', 16, 8, 21, 21, { border_radius: 10.5, border_width: 0.6, border_color: '#0f766e' }),
                field('full_name', 16, 32, 66, 6, { font_size: 11, bold: true, auto_fit: true, single_line: true }),
                field('designation', 16, 38.5, 66, 4.5, { font_size: 8, color: MUTED }),
                field('employee_no', 16, 44, 40, 4.5, { font_size: 8, bold: true, label_color: '#0f766e' }, 'ID'),
                box('cardholder_signature', 60, 43, 22, 7),
                box('shape', 16, 51, 66, 0.4, { background: '#0f766e' }),
                ...backSide(landscape),
            ],
        },
    },
    {
        key: 'portrait',
        file: {
            format: LAYOUT_FORMAT,
            version: 1,
            size_mm: portrait,
            elements: [
                box('background', 0, 0, 54, 85.6, { background: '#ffffff' }),
                box('shape', 0, 0, 54, 18, { background: NAVY, gradient_to: '#1d4ed8', gradient_direction: 'to bottom right' }),
                field('organization.name', 3, 2, 48, 9, { color: '#ffffff', font_size: 10, bold: true, uppercase: true, align: 'center', vertical_align: 'middle', auto_fit: true }),
                box('photo', 15, 12, 24, 24, { border_radius: 12, border_width: 0.8, border_color: '#ffffff' }),
                field('full_name', 3, 39, 48, 6.5, { font_size: 12, bold: true, align: 'center', auto_fit: true, single_line: true }),
                field('designation', 3, 46, 48, 5, { font_size: 8.5, color: MUTED, align: 'center', auto_fit: true, single_line: true }),
                field('department', 3, 51.5, 48, 4.5, { font_size: 7.5, color: MUTED, align: 'center', auto_fit: true, single_line: true }),
                field('employee_no', 3, 58, 48, 5, { font_size: 9, bold: true, align: 'center', label_color: NAVY }, 'ID'),
                box('signatory_signature', 16, 65, 22, 7),
                box('shape', 0, 77, 54, 8.6, { background: NAVY }),
                field('card.expiry_date', 3, 77, 48, 8.6, { color: '#ffffff', font_size: 7.5, align: 'center', vertical_align: 'middle', label_color: '#c7d2fe' }, 'Valid till'),
                ...backSide(portrait),
            ],
        },
    },
];
