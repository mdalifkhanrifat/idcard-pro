<script setup lang="ts">
import UiSortHeader from '@/components/ui/UiSortHeader.vue';
import { formatNumber } from '@/lib/format';
import { formatReportValue, type ReportColumn, type ReportValue } from './reports';

/**
 * Rows of any report with the chosen columns (each row carries `values` by column key). A page may draw a
 * column itself through the `cell` slot (e.g. the holder's name as a link).
 */
defineProps<{
    columns: ReportColumn[];
    rows: { id: string; values?: Record<string, ReportValue> }[];
    loading?: boolean;
    sort?: string;
    /** Column key => sort field of the report, for the columns it can sort by. */
    sortable?: Record<string, string>;
    /** The organization's time zone: moments are shown in it, as downloads have them. */
    timeZone?: string;
    /** Number of the first row (e.g. 16 on the second page of 15): rows are numbered as the legacy "#" column. */
    firstRow?: number;
}>();
const emit = defineEmits<{ toggleSort: [field: string] }>();
</script>

<template>
    <!-- relative: keeps anything absolutely placed inside the scroll box (no page widening on phones) -->
    <div class="relative overflow-x-auto" data-report-table>
        <table class="w-full text-sm">
            <thead class="text-left text-xs font-medium tracking-wide whitespace-nowrap text-muted uppercase">
                <tr class="border-b border-line">
                    <th class="w-px py-3 pr-2 pl-5 text-right" data-column="#">#</th>
                    <th v-for="column in columns" :key="column.key" class="px-4 py-3 last:pr-5" :class="column.type === 'number' && 'text-right'" :data-column="column.key">
                        <UiSortHeader v-if="sortable?.[column.key] && sort !== undefined" :field="sortable[column.key]" :sort="sort" :label="column.label" @toggle="emit('toggleSort', $event)" />
                        <template v-else>{{ column.label }}</template>
                    </th>
                </tr>
            </thead>
            <tbody :class="loading && 'opacity-50'">
                <tr v-for="(row, index) in rows" :key="row.id" class="border-b border-line last:border-0 hover:bg-surface-2" data-report-row>
                    <td class="py-3 pr-2 pl-5 text-right align-top text-muted tabular-nums" data-row-number>{{ formatNumber((firstRow ?? 1) + index) }}</td>
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        class="px-4 py-3 align-top last:pr-5"
                        :class="{
                            'text-right tabular-nums': column.type === 'number',
                            'whitespace-nowrap': column.type === 'date' || column.type === 'datetime' || column.type === 'number',
                            'text-muted': (row.values?.[column.key] ?? null) === null,
                        }"
                    >
                        <!-- Text as wide as it needs up to a limit (cells ignore max-width): short text on one line, long text wraps,
                             a long unbroken word (e-mail, link, code) breaks inside it instead of running over the next columns -->
                        <div :class="column.type === 'text' && 'w-max max-w-xs break-words'">
                            <slot name="cell" :column="column" :row="row" :value="row.values?.[column.key] ?? null" :text="formatReportValue(column, row.values?.[column.key], timeZone)">
                                {{ formatReportValue(column, row.values?.[column.key], timeZone) }}
                            </slot>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
