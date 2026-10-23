import { describe, expect, it } from 'vitest';
import { cameraProblem, numberFromQr } from '@/lib/appointments/scan';

describe('reading an appointment\'s QR code', () => {
    it('takes the number itself, or from a link naming it', () => {
        expect(numberFromQr(' A-26-00012 \n')).toBe('A-26-00012');
        expect(numberFromQr('https://ids.example.com/a/A-26-00012')).toBe('A-26-00012');
        expect(numberFromQr('https://ids.example.com/appointments?number=A-26-00012&x=1')).toBe('A-26-00012');
        expect(numberFromQr('   ')).toBeNull();
        expect(numberFromQr('https://ids.example.com/')).toBeNull();
    });

    it('names why the camera cannot be used', () => {
        expect(cameraProblem(new DOMException('', 'NotAllowedError'))).toBe('permission');
        expect(cameraProblem(new DOMException('', 'NotFoundError'))).toBe('no_device');
        expect(cameraProblem(new DOMException('', 'NotReadableError'))).toBe('busy');
        expect(cameraProblem(new Error('odd'))).toBe('device');
        expect(cameraProblem(Object.assign(new Error('insecure'), { name: 'InsecureError' }))).toBe('insecure');
    });
});
