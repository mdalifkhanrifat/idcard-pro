import { http } from '@/lib/http';

/** One run of the HR sync (6.3): who sent it, what kind, how many of each result. What was sent is never kept. */
export interface SyncRun {
    id: string;
    kind: 'upsert' | 'deactivate';
    account: { id: string; name: string; kind: string; deleted: boolean } | null;
    total: number;
    created: number;
    updated: number;
    unchanged: number;
    failed: number;
    not_found: number;
    started_at: string;
    /** Null: the run broke off (what was saved until then stays). */
    finished_at: string | null;
}

/** A field a sync record may hold, named in the reader's language. */
export interface SyncField {
    field: string;
    label: string;
}

/** The organization's HR sync settings. */
export interface SyncSettings {
    /** The HR system's own field names: theirs => ours. */
    sync_fields: Record<string, string>;
    sync_prune_log: boolean;
    sync_log_days: number;
}

export interface SyncSettingsAnswer {
    data: SyncSettings;
    meta: { log_days: { min: number; max: number }; can_update: boolean };
}

/** One line of the field-name editor. */
export interface FieldNameRow {
    theirs: string;
    ours: string;
}

export async function getSyncSettings(): Promise<SyncSettingsAnswer> {
    return (await http.get<SyncSettingsAnswer>('/sync/cardholders/settings')).data;
}

/** What is left out stays; the whole map of field names is sent when it is. */
export async function saveSyncSettings(change: Partial<SyncSettings>): Promise<SyncSettingsAnswer> {
    return (await http.put<SyncSettingsAnswer>('/sync/cardholders/settings', change)).data;
}

export async function getSyncFields(): Promise<SyncField[]> {
    return (await http.get<{ data: SyncField[] }>('/sync/cardholders/fields')).data.data;
}

/** The map as editable lines, by HR name. */
export function rowsFromMap(map: Record<string, string>): FieldNameRow[] {
    return Object.entries(map)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([theirs, ours]) => ({ theirs, ours }));
}

/** The lines as the map the server keeps: names trimmed, a line not filled in left out (the server says what else is wrong). */
export function mapFromRows(rows: FieldNameRow[]): Record<string, string> {
    const map: Record<string, string> = {};
    for (const row of rows) {
        const theirs = row.theirs.trim();
        if (theirs !== '' && row.ours !== '') {
            map[theirs] = row.ours;
        }
    }
    return map;
}

/** The name a record should use for one of our fields: the organization's HR name for it, else ours. */
export function nameFor(field: string, map: Record<string, string>): string {
    return Object.entries(map).find(([, ours]) => ours === field)?.[0] ?? field;
}

/**
 * A request an HR system's developer can start from, in the organization's own field names. The token is never shown
 * here (it is shown once, when made).
 */
export function sampleRequest(origin: string, kind: SyncRun['kind'], map: Record<string, string>): string {
    const body =
        kind === 'deactivate'
            ? { employee_nos: ['E-1001', 'E-1002'], dry_run: true }
            : {
                  dry_run: true,
                  records: [
                      {
                          [nameFor('employee_no', map)]: 'E-1001',
                          [nameFor('full_name', map)]: 'Rahim Uddin',
                          [nameFor('department', map)]: 'Operations',
                          [nameFor('family', map)]: [{ external_id: 'F-1', relation: 'spouse', name: 'Salma Begum' }],
                      },
                  ],
              };
    const path = kind === 'deactivate' ? '/api/v1/sync/cardholders/deactivate' : '/api/v1/sync/cardholders';

    return [
        `curl -X POST ${origin}${path} \\`,
        '  -H "Authorization: Bearer <token>" \\',
        '  -H "Accept: application/json" \\',
        '  -H "Content-Type: application/json" \\',
        '  -H "Idempotency-Key: <one per batch>" \\',
        // Laid out to be read; a shell takes the quoted lines as one argument
        `  -d '${JSON.stringify(body, null, 2).replaceAll('\n', '\n  ')}'`,
    ].join('\n');
}

/** How a run went, for its badge. */
export function runTone(run: Pick<SyncRun, 'finished_at' | 'failed'>): 'danger' | 'warning' | 'success' {
    if (run.finished_at === null) {
        return 'danger';
    }
    return run.failed > 0 ? 'warning' : 'success';
}
