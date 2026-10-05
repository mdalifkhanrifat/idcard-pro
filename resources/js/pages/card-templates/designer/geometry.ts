import type { DesignElementStyle } from '@/types';

export interface Box {
    x: number;
    y: number;
    width: number;
    height: number;
}

/** Edges of the area an element covers once tilted by `style.angle` around its centre (mm). */
export function tiltedBounds(box: Box, angle = 0): { left: number; top: number; right: number; bottom: number } {
    const radians = (angle * Math.PI) / 180;
    // Same formula as CardTemplateDesignService::tiltedInside(), so the designer and the server agree.
    const halfWidth = Math.abs((box.width / 2) * Math.cos(radians)) + Math.abs((box.height / 2) * Math.sin(radians));
    const halfHeight = Math.abs((box.width / 2) * Math.sin(radians)) + Math.abs((box.height / 2) * Math.cos(radians));
    const centreX = box.x + box.width / 2;
    const centreY = box.y + box.height / 2;

    return { left: centreX - halfWidth, top: centreY - halfHeight, right: centreX + halfWidth, bottom: centreY + halfHeight };
}

/** The tilted area must stay on the card, unless the element may bleed past the edge. */
export function keepsTiltInside(style: DesignElementStyle): boolean {
    return !!style.angle && !style.bleed;
}

/**
 * The part of an element that has to stay on the card: its box, and when tilted (without bleed) also the
 * tilted area — the server checks both.
 */
export function coveredArea(box: Box, style: DesignElementStyle): { left: number; top: number; right: number; bottom: number } {
    const own = { left: box.x, top: box.y, right: box.x + box.width, bottom: box.y + box.height };
    if (!keepsTiltInside(style)) {
        return own;
    }
    const tilted = tiltedBounds(box, style.angle);

    return {
        left: Math.min(own.left, tilted.left),
        top: Math.min(own.top, tilted.top),
        right: Math.max(own.right, tilted.right),
        bottom: Math.max(own.bottom, tilted.bottom),
    };
}

/**
 * Move a tilted element the least distance that brings its covered area onto the card. When that area
 * is larger than the card it is centred, and the server reports it.
 */
export function fitTilted(box: Box, style: DesignElementStyle, card: { width: number; height: number }): Box {
    if (!keepsTiltInside(style)) {
        return box;
    }
    const area = coveredArea(box, style);
    const shift = (low: number, high: number, limit: number): number => (high - low > limit ? (limit - low - high) / 2 : low < 0 ? -low : high > limit ? limit - high : 0);

    return { ...box, x: box.x + shift(area.left, area.right, card.width), y: box.y + shift(area.top, area.bottom, card.height) };
}

/** Normalise any angle to −180..180 degrees. */
export function normaliseAngle(degrees: number): number {
    const value = ((((degrees + 180) % 360) + 360) % 360) - 180;

    return Math.round(value * 10) / 10;
}
