type Rgb = [number, number, number];

/** Surfaces brand-coloured text sits on (design tokens in app.css), light and dark. */
const LIGHT_SURFACES = ['#ffffff', '#f6f7f9', '#f1f3f6'];
const DARK_SURFACES = ['#0b0d12', '#12151c', '#1a1e27'];
/** WCAG AA contrast for normal text. */
const MIN_CONTRAST = 4.5;

const toRgb = (hex: string): Rgb => [1, 3, 5].map((start) => parseInt(hex.slice(start, start + 2), 16)) as Rgb;
const toHex = (rgb: Rgb): string => `#${rgb.map((part) => Math.round(part).toString(16).padStart(2, '0')).join('')}`;

function luminance([r, g, b]: Rgb): number {
    const linear = (part: number) => {
        const value = part / 255;

        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
}

function contrast(a: Rgb, b: Rgb): number {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (light + 0.05) / (dark + 0.05);
}

/**
 * The brand colour, mixed towards black or white only as far as needed to stay readable on every surface,
 * so text, outlines and active tabs in the brand colour work with any organization colour in both themes.
 */
function readableOn(color: Rgb, surfaces: string[], towards: Rgb): string {
    const backgrounds = surfaces.map(toRgb);
    for (let step = 0; step <= 20; step++) {
        const mixed = color.map((part, index) => part + (towards[index] - part) * (step / 20)) as Rgb;
        if (backgrounds.every((background) => contrast(mixed, background) >= MIN_CONTRAST)) {
            return toHex(mixed);
        }
    }

    return toHex(towards);
}

/**
 * Applies the organization's brand colour to the design tokens: `--brand` for filled areas with a readable
 * text colour on top (`--brand-contrast`), and `--brand-ink-light` / `--brand-ink-dark` for brand-coloured
 * text and outlines on the light and dark surfaces (`text-brand-ink`, `border-brand-ink`).
 */
export function applyBrand(hex: string | null | undefined): void {
    const style = document.documentElement.style;
    for (const [name, value] of Object.entries(brandTokens(hex))) {
        style.setProperty(name, value);
    }
}

/**
 * The brand design tokens for a colour, e.g. as inline style on an element marked `data-brand-scope`
 * to show another organization's colours in one part of a page (the public card verification page).
 */
export function brandTokens(hex: string | null | undefined): Record<string, string> {
    const color = hex && /^#[0-9a-f]{6}$/i.test(hex) ? hex : '#0f62fe';
    const rgb = toRgb(color);

    return {
        '--brand': color,
        '--brand-contrast': contrast(rgb, [17, 24, 39]) > contrast(rgb, [255, 255, 255]) ? '#111827' : '#ffffff',
        '--brand-ink-light': readableOn(rgb, LIGHT_SURFACES, [0, 0, 0]),
        '--brand-ink-dark': readableOn(rgb, DARK_SURFACES, [255, 255, 255]),
    };
}
