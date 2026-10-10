import { describe, expect, it, vi } from 'vitest';
import { cameraError, webcamDriver } from '../drivers/webcam';
import { registryOptions } from './fixtures';

const request = { modality: 'face', position: 'face', options: registryOptions() };

function devices(list: { kind: string }[], getUserMedia = vi.fn()) {
    vi.stubGlobal('navigator', { mediaDevices: { enumerateDevices: vi.fn(async () => list), getUserMedia } });

    return getUserMedia;
}

describe('webcam driver', () => {
    it('is ready when the browser offers a camera, and says why not otherwise', async () => {
        devices([{ kind: 'audioinput' }, { kind: 'videoinput' }]);
        expect(await webcamDriver.probe(request)).toEqual({ ready: true });

        devices([{ kind: 'audioinput' }]);
        expect(await webcamDriver.probe(request)).toEqual({ ready: false, reason: 'no_device' });

        // Cameras exist only on HTTPS sites (and localhost)
        vi.stubGlobal('navigator', {});
        expect(await webcamDriver.probe(request)).toEqual({ ready: false, reason: 'insecure' });
    });

    it('asks for the station camera when one was chosen, else the front camera, never the microphone', async () => {
        const getUserMedia = devices([{ kind: 'videoinput' }], vi.fn(async () => ({}) as MediaStream));

        await webcamDriver.openStream!({ deviceId: 'cam-2' });
        await webcamDriver.openStream!({});

        expect(getUserMedia.mock.calls[0][0]).toMatchObject({ audio: false, video: { deviceId: { exact: 'cam-2' } } });
        expect(getUserMedia.mock.calls[1][0]).toMatchObject({ audio: false, video: { facingMode: 'user' } });
    });

    it('explains camera errors', async () => {
        const named = (name: string) => Object.assign(new Error(name), { name });
        expect(cameraError(named('NotAllowedError')).code).toBe('permission');
        expect(cameraError(named('NotFoundError')).code).toBe('no_device');
        expect(cameraError(named('NotReadableError')).code).toBe('busy');
        expect(cameraError(named('Whatever')).code).toBe('device');

        devices([{ kind: 'videoinput' }], vi.fn(async () => Promise.reject(named('NotAllowedError'))));
        await expect(webcamDriver.openStream!({})).rejects.toMatchObject({ code: 'permission' });
    });

    it('needs a playing preview to take the picture from', async () => {
        await expect(webcamDriver.capture({ ...request, video: null })).rejects.toMatchObject({ code: 'no_device' });
    });
});
