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
                loaded.set(
                    key,
                    face.load().then(
                        () => {
                            document.fonts.add(face);

                            return true;
                        },
                        () => {
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
