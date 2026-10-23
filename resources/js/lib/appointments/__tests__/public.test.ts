import { describe, expect, it } from 'vitest';
import { bookableServices } from '@/lib/appointments/public';

describe('what the public link can book', () => {
    const service = (key: string) => ({ key, name: key });
    const place = (services: string[]) => ({ id: services.join('-') || 'none', name: 'Place', name_local: null, address: null, services });

    it('offers a kind of visit only where an open place takes it', () => {
        expect(bookableServices({ services: [service('new_card'), service('renewal')], places: [place(['renewal', 'biometrics'])] })).toEqual([service('renewal')]);
        // Nothing offered, or no place: nothing to book
        expect(bookableServices({ services: [], places: [place(['renewal'])] })).toEqual([]);
        expect(bookableServices({ services: [service('renewal')], places: [] })).toEqual([]);
    });
});
