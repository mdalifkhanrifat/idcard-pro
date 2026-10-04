import { http } from '@/lib/http';
import type { CardTemplate, DesignElement } from '@/types';

/**
 * Layout files: a whole design as JSON, with its logo and background images embedded as data URLs,
 * so it can be backed up or moved to another template or organization.
 */
export const LAYOUT_FORMAT = 'idcard-pro.card-layout';

export type LayoutElement = Omit<DesignElement, 'id' | 'image' | 'image_url'> & { image_data?: string | null };

export interface LayoutFile {
    format: typeof LAYOUT_FORMAT;
    version: 1;
    /** Card size in mm the positions refer to. */
    size_mm: { width: number; height: number };
    elements: LayoutElement[];
}

async function toDataUrl(url: string): Promise<string> {
    const blob = (await http.get(url, { baseURL: '', responseType: 'blob' })).data as Blob;

    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(blob);
    });
}

export async function buildLayoutFile(elements: DesignElement[], size: { width: number; height: number }): Promise<LayoutFile> {
    const out: LayoutElement[] = [];
    for (const element of elements) {
        out.push({
            side: element.side,
            type: element.type,
            field: element.field,
            label: element.label,
            content: element.content,
            qr_lines: element.qr_lines,
            x: element.x,
            y: element.y,
            width: element.width,
            height: element.height,
            style: { ...element.style },
            image_data: element.image_url ? await toDataUrl(element.image_url) : null,
        });
    }

    return { format: LAYOUT_FORMAT, version: 1, size_mm: size, elements: out };
}

export function downloadLayout(file: LayoutFile, template: CardTemplate): void {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([JSON.stringify(file, null, 2)], { type: 'application/json' }));
    link.download = `${template.code || 'card'}-layout.json`;
    link.click();
    URL.revokeObjectURL(link.href);
}

export function parseLayoutFile(text: string): LayoutFile {
    const data = JSON.parse(text) as Partial<LayoutFile>;
    if (data.format !== LAYOUT_FORMAT || !Array.isArray(data.elements) || !data.size_mm) {
        throw new Error('format');
    }

    return data as LayoutFile;
}

/** Style values measured in mm or pt that grow and shrink with the layout. */
const SCALED_STYLE = ['font_size', 'letter_spacing', 'padding', 'border_width', 'border_radius'] as const;

/**
 * Turn a layout file into elements of this template: images are uploaded again. When the card size
 * differs, everything is scaled by one factor (so nothing is stretched) and centred; a background
 * always covers the whole side. `turned` tells that the orientation differs, so the result needs rearranging.
 */
export async function importLayout(file: LayoutFile, templateId: string, size: { width: number; height: number }): Promise<{ elements: DesignElement[]; scaled: boolean; turned: boolean }> {
    const factor = Math.min(size.width / file.size_mm.width, size.height / file.size_mm.height);
    const scaled = Math.abs(factor - 1) > 0.001 || Math.abs(size.width - file.size_mm.width) > 0.05 || Math.abs(size.height - file.size_mm.height) > 0.05;
    const turned = (size.width > size.height) !== (file.size_mm.width > file.size_mm.height);
    const offsetX = (size.width - file.size_mm.width * factor) / 2;
    const offsetY = (size.height - file.size_mm.height * factor) / 2;
    const round = (value: number) => Math.round(value * 10) / 10;
    const elements: DesignElement[] = [];

    for (const { image_data: imageData, ...element } of file.elements) {
        let image: string | null = null;
        let imageUrl: string | null = null;
        if (imageData) {
            const blob = await (await fetch(imageData)).blob();
            const body = new FormData();
            body.append('file', blob, `image.${blob.type.split('/')[1] ?? 'png'}`);
            const { data } = await http.post(`/card-templates/${templateId}/images`, body);
            image = data.data.image;
            imageUrl = data.data.image_url;
        }
        const style = { ...element.style };
        if (scaled) {
            for (const key of SCALED_STYLE) {
                if (style[key] !== undefined) {
                    style[key] = Math.round(style[key] * factor * 10) / 10;
                }
            }
            if (style.font_size !== undefined) {
                style.font_size = Math.min(72, Math.max(1, style.font_size));
            }
        }
        const rect =
            element.type === 'background'
                ? { x: 0, y: 0, width: size.width, height: size.height }
                : {
                      x: round(offsetX + element.x * factor),
                      y: round(offsetY + element.y * factor),
                      width: Math.max(0.2, Math.min(round(element.width * factor), size.width)),
                      height: Math.max(0.2, Math.min(round(element.height * factor), size.height)),
                  };
        elements.push({ ...element, style, image, image_url: imageUrl, ...rect });
    }

    return { elements, scaled, turned };
}
