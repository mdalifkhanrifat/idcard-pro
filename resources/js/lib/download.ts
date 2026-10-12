import type { AxiosResponse } from 'axios';

/**
 * The file name a download response gives (Content-Disposition, quoted, plain or RFC 5987 `filename*`),
 * else the fallback.
 */
export function downloadFilename(contentDisposition: unknown, fallback: string): string {
    const header = String(contentDisposition ?? '');
    const encoded = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(header)?.[1];
    if (encoded) {
        try {
            return decodeURIComponent(encoded.trim().replace(/^"|"$/g, ''));
        } catch {
            // Malformed: try the plain name
        }
    }

    return /filename="?([^";]+)"?/i.exec(header)?.[1]?.trim() || fallback;
}

/** Saves a downloaded file (a blob response) under the name the server gave it. */
export function saveDownload(response: AxiosResponse<Blob>, fallback: string): void {
    const url = URL.createObjectURL(response.data);
    Object.assign(document.createElement('a'), { href: url, download: downloadFilename(response.headers['content-disposition'], fallback) }).click();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

/** A failed blob request carries its JSON error as a Blob; read it so the message can be shown. */
export async function readBlobError(error: unknown): Promise<Error> {
    const data = (error as { response?: { data?: unknown } }).response?.data;
    if (data instanceof Blob) {
        try {
            const body = JSON.parse(await data.text()) as { message?: string };
            if (body.message) {
                return new Error(body.message);
            }
        } catch {
            // Not JSON: fall through to the generic message
        }
    }

    return error instanceof Error ? error : new Error(String(error));
}
