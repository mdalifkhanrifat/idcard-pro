/**
 * Applies the organization's brand colour to the design tokens and picks a readable text colour on top of it.
 */
export function applyBrand(hex: string | null | undefined): void {
    const color = hex && /^#[0-9a-f]{6}$/i.test(hex) ? hex : '#0f62fe';
    const r = parseInt(color.slice(1, 3), 16);
    const g = parseInt(color.slice(3, 5), 16);
    const b = parseInt(color.slice(5, 7), 16);
    const luminance = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;

    document.documentElement.style.setProperty('--brand', color);
    document.documentElement.style.setProperty('--brand-contrast', luminance > 0.6 ? '#111827' : '#ffffff');
}
