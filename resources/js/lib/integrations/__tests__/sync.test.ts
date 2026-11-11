import { describe, expect, it } from 'vitest';
import { mapFromRows, nameFor, rowsFromMap, runTone, sampleRequest } from '../sync';

describe('the HR field names', () => {
    it('turns the map into lines by HR name and back, leaving out lines not filled in', () => {
        expect(rowsFromMap({ name: 'full_name', emp_id: 'employee_no' })).toEqual([
            { theirs: 'emp_id', ours: 'employee_no' },
            { theirs: 'name', ours: 'full_name' },
        ]);
        expect(mapFromRows([{ theirs: ' emp_id ', ours: 'employee_no' }, { theirs: '', ours: 'rank' }, { theirs: 'dept', ours: '' }])).toEqual({ emp_id: 'employee_no' });
    });

    it('writes the sample request in the organization\'s own names', () => {
        expect(nameFor('employee_no', { emp_id: 'employee_no' })).toBe('emp_id');
        expect(nameFor('rank', { emp_id: 'employee_no' })).toBe('rank');

        const sample = sampleRequest('https://ids.example.org', 'upsert', { emp_id: 'employee_no', dependents: 'family' });
        expect(sample).toContain('curl -X POST https://ids.example.org/api/v1/sync/cardholders \\');
        expect(sample).toContain('Idempotency-Key');
        expect(sample).toContain('"emp_id": "E-1001"');
        expect(sample).toContain('"dependents": [');
        // The body is laid out, and is still the JSON sent
        const body = sample.slice(sample.indexOf("-d '") + 4, sample.lastIndexOf("'"));
        expect(JSON.parse(body).records[0].emp_id).toBe('E-1001');
        expect(sample).not.toContain('employee_no');
        expect(sampleRequest('https://ids.example.org', 'deactivate', {})).toContain('/api/v1/sync/cardholders/deactivate');
    });
});

describe('a run', () => {
    it('is coloured by how it went', () => {
        expect(runTone({ finished_at: null, failed: 0 })).toBe('danger');
        expect(runTone({ finished_at: '2026-11-11T10:00:00Z', failed: 2 })).toBe('warning');
        expect(runTone({ finished_at: '2026-11-11T10:00:00Z', failed: 0 })).toBe('success');
    });
});
