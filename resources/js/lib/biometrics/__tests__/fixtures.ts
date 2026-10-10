import { vi } from 'vitest';
import type { BiometricOptions } from '@/types';

/** The registry as the API sends it (config/biometrics.php), trimmed to what the drivers read. */
export function registryOptions(): BiometricOptions {
    return {
        modalities: {
            fingerprint: {
                positions: ['right_thumb', 'left_thumb', 'right_index'],
                groups: { right_hand: ['right_thumb', 'right_index'], left_hand: ['left_thumb'] },
                printable: true,
                drivers: ['upload', 'simulator', 'secugen', 'bridge'],
                image_formats: ['png', 'jpeg', 'bmp', 'wsq'],
                template_formats: ['iso-19794-2', 'ansi-378', 'iso-19794-2-2011'],
            },
            face: { positions: ['face'], groups: [], printable: false, drivers: ['upload', 'bridge'], image_formats: ['jpeg', 'png'], template_formats: [] },
        },
        drivers: {
            upload: { modalities: ['fingerprint', 'face'], local_url: null, testing: false, client: {} },
            simulator: { modalities: ['fingerprint', 'face'], local_url: null, testing: true, client: {} },
            secugen: { modalities: ['fingerprint'], local_url: 'https://localhost:8443', testing: false, client: { license: 'LIC' } },
            bridge: { modalities: ['fingerprint', 'face'], local_url: 'http://127.0.0.1:47800', testing: false, client: {} },
        },
        modes: ['off', 'optional', 'required'],
        required_before: ['issue', 'print'],
        unavailable_reasons: ['missing', 'other'],
        unavailable_reasons_with_note: ['other'],
        image_types: {
            png: { signature: '89504e47', mime: 'image/png', displayable: true },
            jpeg: { signature: 'ffd8ff', mime: 'image/jpeg', displayable: true },
            bmp: { signature: '424d', mime: 'image/bmp', displayable: true },
            wsq: { signature: 'ffa0', mime: 'application/octet-stream', displayable: false },
        },
        max_image_kb: 2048,
        max_template_kb: 64,
    };
}

export const PNG_BASE64 = btoa(String.fromCharCode(0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 1, 2, 3));

/** localStorage for the node test environment. */
export function memoryStorage(): Storage {
    const items = new Map<string, string>();
    const storage = {
        getItem: (key: string) => items.get(key) ?? null,
        setItem: (key: string, value: string) => void items.set(key, String(value)),
        removeItem: (key: string) => void items.delete(key),
        clear: () => items.clear(),
        key: (index: number) => [...items.keys()][index] ?? null,
        get length() {
            return items.size;
        },
    };
    vi.stubGlobal('localStorage', storage);

    return storage as Storage;
}

/** A fake fetch answering by path; records what was sent. */
export function fakeFetch(answer: (path: string, init: RequestInit) => Response | Promise<Response>) {
    const calls: { url: string; init: RequestInit }[] = [];
    const fetch = vi.fn(async (url: string, init: RequestInit) => {
        calls.push({ url, init });

        return answer(new URL(url).pathname, init);
    });
    vi.stubGlobal('fetch', fetch);

    return calls;
}

export const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

/** A fetch that never answers until its signal aborts (a scanner waiting for a finger). */
export const hanging = (init: RequestInit) =>
    new Promise<Response>((_, reject) => init.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))));
