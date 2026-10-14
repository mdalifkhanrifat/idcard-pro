<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuSeparator, DropdownMenuTrigger } from 'reka-ui';
import { ChevronDown, FileBarChart, FileDown, Printer } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import { formatNumber } from '@/lib/format';
import { useUiStore } from '@/stores/ui';
import { downloadReport, printReport, reportSlug, useChosenColumns, useReports } from './reports';

/**
 * Downloads (CSV, Excel, PDF) and print of a registry report with the criteria a page shows: as buttons on the
 * report's own page, or as one menu on a list page whose list is that report (e.g. cardholders), with a link
 * to open the same criteria as the report. Nothing is shown for a report the reader may not see.
 */
const props = withDefaults(
    defineProps<{
        reportKey: string;
        /** The criteria as request parameters (filter[...], sort), read at the click. */
        params: () => Record<string, string>;
        /** Rows the criteria find now (limits are checked against it). */
        total: number;
        /** The columns to download; the reader's own choice for the report (or the organization's) when left out. */
        columns?: string[];
        variant?: 'buttons' | 'menu';
        /** The report page's link query when the list keeps its criteria under other names (f_<filter>, sort). */
        linkQuery?: Record<string, string>;
    }>(),
    { variant: 'buttons', columns: undefined, linkQuery: undefined },
);

const { t } = useI18n();
const route = useRoute();
const ui = useUiStore();
const registry = useReports();
const report = computed(() => registry.find(props.reportKey));
const chosen = useChosenColumns(report);
const columnKeys = computed(() => props.columns ?? chosen.keys.value);

/** Rows one file of this format may hold (a PDF holds fewer than a spreadsheet). */
const limitOf = (format: string) => report.value?.format_limits?.[format] ?? report.value?.max_export_rows ?? Infinity;
const tooMany = (format: string) => props.total > limitOf(format);
/** Columns one file of this format may show (a PDF must stay readable on paper; a spreadsheet holds any number). */
const columnLimitOf = (format: string) => report.value?.format_column_limits?.[format] ?? Infinity;
const tooManyColumns = (format: string) => columnKeys.value.length > columnLimitOf(format);
const blocked = (format: string) => tooMany(format) || tooManyColumns(format);
/** Why a format is off, in full (title) and short (shown beside it, as phones show no titles). */
function blockedText(format: string): string | undefined {
    if (tooMany(format)) {
        return t('reports.download.tooMany', { max: formatNumber(limitOf(format)) });
    }

    return tooManyColumns(format) ? t('reports.download.tooManyColumns', { max: formatNumber(columnLimitOf(format)) }) : undefined;
}
function blockedShort(format: string): string {
    if (tooMany(format)) {
        return t('reports.download.atMost', { max: formatNumber(limitOf(format)) });
    }

    return tooManyColumns(format) ? t('reports.download.atMostColumns', { max: formatNumber(columnLimitOf(format)) }) : '';
}
const formats = computed(() => report.value?.formats ?? []);
const canPrint = computed(() => formats.value.includes('pdf'));
const downloading = ref<string | null>(null);

/** The same criteria on the report's page: list pages keep them in the link under the same names; only the report's own filters go. */
const reportLink = computed(() => {
    const known = new Set((report.value?.filters ?? []).map((field) => `f_${field.key}`));
    const query = props.linkQuery ?? Object.fromEntries(Object.entries(route.query).filter(([key]) => key === 'sort' || known.has(key)));

    return { path: `/reports/${reportSlug(props.reportKey)}`, query };
});

async function download(format: string): Promise<void> {
    downloading.value = format;
    try {
        await downloadReport(props.reportKey, format, props.params(), columnKeys.value);
    } catch (error) {
        ui.notify((error as Error).message, 'error');
    } finally {
        downloading.value = null;
    }
}

async function print(): Promise<void> {
    downloading.value = 'print';
    try {
        await printReport(props.reportKey, props.params(), columnKeys.value, t('reports.download.preparing'));
    } catch (error) {
        ui.notify((error as Error).message === 'popup-blocked' ? t('reports.download.popupBlocked') : (error as Error).message, 'error');
    } finally {
        downloading.value = null;
    }
}

onMounted(() => {
    // Without reports.view there is no registry to read: nothing to show
    registry.load().catch(() => undefined);
});
</script>

<template>
    <template v-if="report && variant === 'buttons'">
        <UiButton
            v-for="format in formats"
            :key="format"
            variant="secondary"
            :disabled="blocked(format) || !total"
            :loading="downloading === format"
            :title="blockedText(format)"
            :data-download="format"
            @click="download(format)"
        >
            <FileDown class="size-4" />{{ t(`reports.download.${format}`) }}
        </UiButton>
        <UiButton v-if="canPrint" variant="secondary" :disabled="blocked('pdf') || !total" :loading="downloading === 'print'" :title="blockedText('pdf')" data-print @click="print">
            <Printer class="size-4" />{{ t('reports.download.print') }}
        </UiButton>
    </template>

    <DropdownMenuRoot v-else-if="report">
        <DropdownMenuTrigger as-child>
            <UiButton variant="secondary" :loading="downloading !== null" data-downloads-menu>
                <FileDown class="size-4" />{{ t('reports.download.menu') }}<ChevronDown class="size-4 text-muted" />
            </UiButton>
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent :side-offset="6" align="end" class="z-50 max-w-[calc(100vw-2rem)] min-w-56 rounded-xl border border-line bg-surface p-1 shadow-xl" data-downloads-items>
                <DropdownMenuItem
                    v-for="format in formats"
                    :key="format"
                    :disabled="blocked(format) || !total"
                    class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm text-fg outline-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50 data-[highlighted]:bg-surface-2"
                    :title="blockedText(format)"
                    :data-download="format"
                    @select="download(format)"
                >
                    <FileDown class="size-4 text-muted" />{{ t(`reports.download.${format}`) }}
                    <span v-if="blocked(format)" class="ml-auto pl-3 text-xs text-muted">{{ blockedShort(format) }}</span>
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="canPrint"
                    :disabled="blocked('pdf') || !total"
                    class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm text-fg outline-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50 data-[highlighted]:bg-surface-2"
                    :title="blockedText('pdf')"
                    data-print
                    @select="print"
                >
                    <Printer class="size-4 text-muted" />{{ t('reports.download.print') }}
                    <span v-if="blocked('pdf')" class="ml-auto pl-3 text-xs text-muted">{{ blockedShort('pdf') }}</span>
                </DropdownMenuItem>
                <DropdownMenuSeparator v-if="formats.length" class="my-1 h-px bg-line" />
                <DropdownMenuItem as-child>
                    <RouterLink :to="reportLink" class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm text-fg outline-none data-[highlighted]:bg-surface-2" data-open-report>
                        <FileBarChart class="size-4 text-muted" />{{ t('reports.download.openAsReport') }}
                    </RouterLink>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
