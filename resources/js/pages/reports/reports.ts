import { computed, ref, watch, type Ref } from 'vue';
import { readBlobError, saveDownload } from '@/lib/download';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { http } from '@/lib/http';

/** A column a report can show (GET /reports): labels come from the server in the reader's language. */
export interface ReportColumn {
    key: string;
    label: string;
    type: 'text' | 'number' | 'date' | 'datetime';
    /** Where the value comes from: the report's own data, card, holder, cardholder, custom. */
    group: string;
}

/** A way to filter a report (the server's ReportFilterField): the page draws it from this alone. */
export interface ReportFilterField {
    key: string;
    type: 'search' | 'date' | 'select' | 'number' | 'template' | 'user' | 'master_data' | 'cardholder';
    label: string;
    options?: { value: string; label: string }[];
    master_data?: string;
    /** A child list's parent filter (e.g. unit inside department). */
    parent?: string;
    /** For a date range's end: the start field. */
    after?: string;
    min?: number;
    max?: number;
    default?: string;
    /** Shown only while these other filters have these values (their default counts). */
    when?: Record<string, string>;
}

/** A report of the server's registry: every page and download is built from this. */
export interface ReportDefinition {
    key: string;
    name: string;
    description: string;
    columns: ReportColumn[];
    /** Column group => its name in the reader's language. */
    groups?: Record<string, string>;
    /** The organization's choice, else the report's own. */
    default_columns: string[];
    /** The report's own default. */
    standard_columns: string[];
    filters: ReportFilterField[];
    /** Column key => sort field. */
    sorts: Record<string, string>;
    default_sort: string;
    /** Totals counted over every page. */
    totals: { key: string; label: string }[];
    /** Download formats (empty without reports.export). */
    formats: string[];
    max_export_rows: number | null;
    /** Rows one file may hold, per format (a PDF holds fewer). */
    format_limits: Record<string, number>;
    /** Columns one file may show, per format that has a limit (a PDF must stay readable on paper). */
    format_column_limits?: Record<string, number>;
}

export type ReportValue = string | number | null;

/** The report's address in the API and the app ("card_prints" => "card-prints"). */
export function reportSlug(key: string): string {
    return key.replaceAll('_', '-');
}

const catalogue = ref<ReportDefinition[] | null>(null);
let pending: Promise<void> | null = null;

/** The report registry, fetched once (again with `force`, e.g. after the organization's columns change). */
export function useReports() {
    function load(force = false): Promise<void> {
        if (!force && (catalogue.value || pending)) {
            return pending ?? Promise.resolve();
        }
        pending = http
            .get<{ data: ReportDefinition[] }>('/reports')
            .then(({ data }) => {
                catalogue.value = data.data;
            })
            .finally(() => {
                pending = null;
            });

        return pending;
    }

    function find(key: string): ReportDefinition | null {
        return catalogue.value?.find((report) => report.key === key) ?? null;
    }

    function replace(report: ReportDefinition): void {
        catalogue.value = (catalogue.value ?? []).map((existing) => (existing.key === report.key ? report : existing));
    }

    return { reports: catalogue, load, find, replace };
}

/**
 * A value as the page shows it: days and moments in the reader's language (moments in the organization's
 * time zone, as downloads have them), numbers in the reader's digits; empty values as a dash.
 */
export function formatReportValue(column: Pick<ReportColumn, 'type'>, value: ReportValue | undefined, timeZone?: string): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    switch (column.type) {
        case 'number':
            return typeof value === 'number' ? formatNumber(value) : String(value);
        case 'date':
            return formatDate(String(value));
        case 'datetime':
            return formatDateTime(String(value), timeZone);
        default:
            return String(value);
    }
}

function storageKey(report: string): string {
    return `report-columns:${report}`;
}

/**
 * The columns this viewer shows in a report: their own choice in this browser, else the organization's
 * default. Choices of columns that no longer exist (e.g. a custom field turned off) are dropped.
 */
export function useChosenColumns(report: Ref<ReportDefinition | null>) {
    const own = ref<string[] | null>(null);

    watch(
        () => report.value?.key,
        (key) => {
            own.value = null;
            if (!key) {
                return;
            }
            try {
                const saved = JSON.parse(localStorage.getItem(storageKey(key)) ?? 'null') as unknown;
                own.value = Array.isArray(saved) && saved.every((item) => typeof item === 'string') ? saved : null;
            } catch {
                own.value = null;
            }
        },
        { immediate: true },
    );

    const keys = computed<string[]>(() => {
        const available = new Set(report.value?.columns.map((column) => column.key) ?? []);
        const chosen = (own.value ?? []).filter((key) => available.has(key));

        return chosen.length ? chosen : (report.value?.default_columns ?? []).filter((key) => available.has(key));
    });
    const columns = computed<ReportColumn[]>(() => keys.value.map((key) => report.value?.columns.find((column) => column.key === key)).filter((column): column is ReportColumn => !!column));
    const isOwn = computed(() => own.value !== null && own.value.some((key) => report.value?.columns.some((column) => column.key === key)));

    /** This viewer's columns (null: back to the organization's default). */
    function choose(next: string[] | null): void {
        own.value = next && next.length ? next : null;
        const key = report.value?.key;
        if (!key) {
            return;
        }
        try {
            if (own.value) {
                localStorage.setItem(storageKey(key), JSON.stringify(own.value));
            } else {
                localStorage.removeItem(storageKey(key));
            }
        } catch {
            // Storage blocked (private window): the choice lasts for this page only
        }
    }

    return { keys, columns, isOwn, choose };
}

/** Saves (or with null resets) the organization's default columns; returns the updated report. */
export async function saveOrganizationColumns(report: string, columns: string[] | null): Promise<ReportDefinition> {
    const { data } = await http.put<{ data: ReportDefinition }>(`/reports/${reportSlug(report)}/columns`, { columns });

    return data.data;
}

/** Downloads a report as a file in the given format, with the list's filter and the chosen columns. */
export async function downloadReport(report: string, format: string, params: Record<string, string>, columns: string[]): Promise<void> {
    try {
        const response = await http.get<Blob>(`/reports/${reportSlug(report)}/export`, { params: { ...params, format, columns }, responseType: 'blob' });
        saveDownload(response, `${reportSlug(report)}.${format}`);
    } catch (error) {
        throw await readBlobError(error);
    }
}

/**
 * Opens the report as a PDF in a new tab to print (shown, not saved). The tab is opened at once, while the
 * click still counts as the user's (pop-up blockers), and shows the PDF when it is ready.
 */
export async function printReport(report: string, params: Record<string, string>, columns: string[], waiting: string): Promise<void> {
    const tab = window.open('', '_blank');
    if (!tab) {
        throw new Error('popup-blocked');
    }
    tab.document.title = waiting;
    tab.document.body.style.font = '14px system-ui, sans-serif';
    tab.document.body.textContent = waiting;
    try {
        const response = await http.get<Blob>(`/reports/${reportSlug(report)}/export`, { params: { ...params, format: 'pdf', inline: 1, columns }, responseType: 'blob' });
        tab.location.href = URL.createObjectURL(response.data);
    } catch (error) {
        tab.close();
        throw await readBlobError(error);
    }
}
