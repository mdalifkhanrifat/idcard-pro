import type { DesignElement, DesignElementStyle } from '@/types';

/** Millimetres per typographic point. */
export const PT_TO_MM = 25.4 / 72;

/** CSS for a fill: a solid colour, or a two-colour gradient when `gradient_to` is set. */
export function fillCss(style: DesignElementStyle): Record<string, string | undefined> {
    if (style.background && style.gradient_to) {
        return { backgroundImage: `linear-gradient(${style.gradient_direction ?? 'to bottom'}, ${style.background}, ${style.gradient_to})` };
    }

    return { backgroundColor: style.background };
}

/** `#rrggbb` with an opacity as an rgba() colour. */
function rgba(hex: string, alpha: number): string {
    const value = Number.parseInt(hex.slice(1), 16);

    return `rgba(${(value >> 16) & 255}, ${(value >> 8) & 255}, ${value & 255}, ${alpha})`;
}

/**
 * Shadow as a drop-shadow filter on the element box: it follows whatever is drawn, so letters of text
 * without a fill, transparent PNG logos and ellipses cast their own shape. Offsets and blur are in mm.
 */
export function shadowCss(style: DesignElementStyle, scale: number): string | undefined {
    if (!style.shadow_color) {
        return undefined;
    }
    const px = (mm: number | undefined) => `${(mm ?? 0) * scale}px`;

    return `drop-shadow(${px(style.shadow_x)} ${px(style.shadow_y)} ${px(style.shadow_blur)} ${rgba(style.shadow_color, style.shadow_opacity ?? 1)})`;
}

/** Fill, border, rounded corners, opacity and shadow of an element box, at `scale` pixels per millimetre. */
export function boxCss(style: DesignElementStyle, scale: number): Record<string, string | number | undefined> {
    return {
        ...fillCss(style),
        border: style.border_width ? `${Math.max(style.border_width * scale, 0.5)}px ${style.border_style ?? 'solid'} ${style.border_color ?? '#111111'}` : undefined,
        borderRadius: style.shape === 'ellipse' ? '50%' : style.border_radius ? `${style.border_radius * scale}px` : undefined,
        opacity: style.opacity,
        filter: shadowCss(style, scale),
    };
}

/** Text layout and typography. */
export function textCss(style: DesignElementStyle, scale: number): Record<string, string | number | undefined> {
    return {
        fontFamily: style.font_family ? `'${style.font_family}', sans-serif` : undefined,
        fontSize: `${(style.font_size ?? 8) * PT_TO_MM * scale}px`,
        lineHeight: style.line_height ?? 1.2,
        letterSpacing: style.letter_spacing ? `${style.letter_spacing * PT_TO_MM * scale}px` : undefined,
        textTransform: style.uppercase ? 'uppercase' : undefined,
        whiteSpace: style.single_line ? 'nowrap' : undefined,
        color: style.color ?? '#111111',
        fontWeight: style.bold ? 700 : 400,
        fontStyle: style.italic ? 'italic' : 'normal',
        textDecoration: style.underline ? 'underline' : 'none',
        textAlign: style.align ?? 'left',
        padding: style.padding ? `${style.padding * scale}px` : undefined,
        justifyContent: { top: 'flex-start', middle: 'center', bottom: 'flex-end' }[style.vertical_align ?? 'top'],
    };
}

/**
 * Turned content: laid out in the rotated box size and centred, so a 90° text runs along the element's height.
 */
export function rotationCss(element: Pick<DesignElement, 'width' | 'height' | 'style'>, scale: number): Record<string, string> | null {
    const rotation = element.style.rotation ?? 0;
    if (!rotation) {
        return null;
    }
    const sideways = rotation === 90 || rotation === 270;
    const width = (sideways ? element.height : element.width) * scale;
    const height = (sideways ? element.width : element.height) * scale;

    return {
        position: 'absolute',
        width: `${width}px`,
        height: `${height}px`,
        left: `${(element.width * scale - width) / 2}px`,
        top: `${(element.height * scale - height) / 2}px`,
        transform: `rotate(${rotation}deg)`,
    };
}

/** How an image fills its box; logos and signatures fit inside, photos and backgrounds fill it. */
export function imageFit(element: Pick<DesignElement, 'type' | 'style'>): 'cover' | 'contain' {
    return element.style.fit ?? (element.type === 'photo' || element.type === 'background' ? 'cover' : 'contain');
}

/** CSS for a card side: background colour or gradient, with the background image on top. */
export function sideCss(background: DesignElement | null, imageUrl: string | null | undefined): Record<string, string | undefined> {
    if (!background) {
        return { backgroundColor: '#ffffff' };
    }
    const fill = fillCss(background.style);
    const layers = [imageUrl ? `url('${imageUrl}')` : null, fill.backgroundImage ?? null].filter(Boolean);

    return {
        backgroundColor: fill.backgroundColor ?? '#ffffff',
        backgroundImage: layers.length ? layers.join(', ') : undefined,
        backgroundSize: imageUrl ? `${imageFit(background)}, auto` : undefined,
        backgroundPosition: 'center',
        backgroundRepeat: 'no-repeat',
    };
}
