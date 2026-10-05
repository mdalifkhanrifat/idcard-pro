import { http } from '@/lib/http';

/**
 * Licensed fonts uploaded by the organization, registered with the browser through the FontFace API
 * so card templates can use them by family name. Files come from our own signed links only.
 */
export interface FontFile {
    family: string;
    weight: number;
    style: string;
    url: string;
}

const loaded = new Map<string, Promise<boolean>>();

/** Load each face once per page; resolves to the faces that failed (unreadable or removed files). */
export async function loadTenantFonts(fonts: FontFile[]): Promise<FontFile[]> {
    const results = await Promise.all(
        fonts.map(async (font) => {
            const key = `${font.family}|${font.weight}|${font.style}`;
            if (!loaded.has(key)) {
                const face = new FontFace(font.family, `url('${font.url}')`, { weight: String(font.weight), style: font.style });
                // Added before loading, so document.fonts reports "loadingdone" and auto-fit text is measured again
                document.fonts.add(face);
                loaded.set(
                    key,
                    face.load().then(
                        () => true,
                        () => {
                            document.fonts.delete(face);
                            loaded.delete(key);

                            return false;
                        },
                    ),
                );
            }

            return (await loaded.get(key)) ? null : font;
        }),
    );

    return results.filter((font): font is FontFile => font !== null);
}

let requested: Promise<void> | null = null;

/** Fetch the organization's font list and load the fonts, once per page (cards, thumbnails). */
export function ensureTenantFonts(): Promise<void> {
    requested ??= http.get('/fonts').then(
        async ({ data }) => {
            await loadTenantFonts(data.data);
        },
        () => {
            requested = null;
        },
    );

    return requested;
}
