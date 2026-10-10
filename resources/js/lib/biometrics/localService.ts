import { isLoopbackUrl } from './station';
import { CaptureError } from './types';

export interface LocalServiceRequest {
    method?: 'GET' | 'POST';
    /** Sent as a form (most vendor services) or as JSON (in a text/plain body, see docs/BIOMETRIC_BRIDGE.md). */
    form?: Record<string, string | number>;
    json?: unknown;
    /** Milliseconds before giving up; a scanner waiting for a finger needs longer than a probe. */
    timeout?: number;
    signal?: AbortSignal;
}

/**
 * Call a capture service running on this computer (a vendor's service or the bridge agent). Network
 * failures, an untrusted certificate, a timeout and errors all become CaptureErrors the station can
 * explain; the station's own cancel stays "cancelled".
 */
export async function callLocalService<T>(baseUrl: string, path: string, request: LocalServiceRequest = {}): Promise<T> {
    if (!isLoopbackUrl(baseUrl)) {
        throw new CaptureError('device', { url: baseUrl });
    }
    const timeout = AbortSignal.timeout(request.timeout ?? 5000);
    const signal = request.signal ? AbortSignal.any([request.signal, timeout]) : timeout;
    const headers: Record<string, string> = {};
    let body: BodyInit | undefined;
    if (request.form) {
        body = new URLSearchParams(Object.entries(request.form).map(([name, value]) => [name, String(value)]));
    } else if (request.json !== undefined) {
        // JSON as text/plain keeps it a simple request: no CORS preflight the local service must answer
        headers['Content-Type'] = 'text/plain;charset=UTF-8';
        body = JSON.stringify(request.json);
    }
    let response: Response;
    try {
        response = await fetch(`${baseUrl.replace(/\/+$/, '')}${path}`, { method: request.method ?? 'POST', headers, body, signal, credentials: 'omit', cache: 'no-store' });
    } catch {
        if (request.signal?.aborted) {
            throw new CaptureError('cancelled');
        }
        if (timeout.aborted) {
            throw new CaptureError('timeout');
        }
        throw new CaptureError('device', { url: baseUrl });
    }
    if (!response.ok) {
        throw new CaptureError('device', { url: baseUrl, status: response.status });
    }
    try {
        return (await response.json()) as T;
    } catch {
        throw new CaptureError('device', { url: baseUrl });
    }
}
