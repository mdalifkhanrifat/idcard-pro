import { describe, expect, it } from 'vitest';
import { callLocalService } from '../localService';
import { CaptureError } from '../types';
import { fakeFetch, hanging, json } from './fixtures';

const BASE = 'http://127.0.0.1:47800';

async function failure(promise: Promise<unknown>): Promise<CaptureError> {
    const error = await promise.then(() => null, (caught: unknown) => caught);
    expect(error).toBeInstanceOf(CaptureError);

    return error as CaptureError;
}

describe('callLocalService', () => {
    it('posts forms and JSON as simple requests (no CORS preflight)', async () => {
        const calls = fakeFetch(() => json({ ok: true }));

        await callLocalService(BASE, '/capture', { form: { Timeout: 10000, Quality: 50 } });
        await callLocalService(`${BASE}/`, '/v1/capture', { json: { modality: 'fingerprint' } });
        await callLocalService(BASE, '/v1/info', { method: 'GET' });

        expect(String(calls[0].init.body)).toBe('Timeout=10000&Quality=50');
        expect(calls[0].init.body).toBeInstanceOf(URLSearchParams);
        expect(calls[1].url).toBe(`${BASE}/v1/capture`);
        expect(calls[1].init.headers).toEqual({ 'Content-Type': 'text/plain;charset=UTF-8' });
        expect(calls[1].init.body).toBe('{"modality":"fingerprint"}');
        expect(calls[2].init.method).toBe('GET');
        expect(calls.every((call) => call.init.credentials === 'omit' && call.init.cache === 'no-store')).toBe(true);
    });

    it('explains service errors, broken answers and an unreachable service as device errors', async () => {
        fakeFetch((path) => (path === '/fail' ? json({}, 500) : new Response('not json')));
        expect((await failure(callLocalService(BASE, '/fail'))).params).toEqual({ url: BASE, status: 500 });
        expect((await failure(callLocalService(BASE, '/bad'))).code).toBe('device');

        fakeFetch(() => Promise.reject(new TypeError('Failed to fetch')));
        expect((await failure(callLocalService(BASE, '/x'))).code).toBe('device');
    });

    it('tells a timeout from the station cancelling', async () => {
        fakeFetch((_, init) => hanging(init));
        expect((await failure(callLocalService(BASE, '/slow', { timeout: 20 }))).code).toBe('timeout');

        const cancel = new AbortController();
        const waiting = callLocalService(BASE, '/slow', { timeout: 5000, signal: cancel.signal });
        cancel.abort();
        expect((await failure(waiting)).code).toBe('cancelled');
    });

    it('refuses any address that is not on this computer, without calling it', async () => {
        const calls = fakeFetch(() => json({}));

        expect((await failure(callLocalService('https://example.com', '/x'))).code).toBe('device');
        expect(calls).toHaveLength(0);
    });
});
