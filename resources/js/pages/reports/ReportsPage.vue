<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronRight, FileBarChart } from 'lucide-vue-next';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import { toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import { reportSlug, useReports } from './reports';

/**
 * Every report of the server's registry the user may see; a new report appears here by itself.
 */
const { t, te } = useI18n();
const ui = useUiStore();
const { reports, load } = useReports();

onMounted(() => load(true).catch((error) => ui.notify(toApiError(error).message, 'error')));
</script>

<template>
    <div>
        <UiPageHeader :title="t('reports.title')" :subtitle="t('reports.subtitle')" />
        <div v-if="reports?.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <RouterLink
                v-for="report in reports"
                :key="report.key"
                :to="`/reports/${reportSlug(report.key)}`"
                class="group flex items-start gap-3 rounded-xl border border-line bg-surface p-4 transition hover:border-brand-ink"
                :data-report-link="report.key"
            >
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-surface-2 text-fg"><FileBarChart class="size-5" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block font-medium text-fg">{{ report.name }}</span>
                    <span v-if="te(`reports.descriptions.${report.key}`)" class="mt-0.5 block text-sm text-muted">{{ t(`reports.descriptions.${report.key}`) }}</span>
                </span>
                <ChevronRight class="mt-1 size-4 shrink-0 text-muted group-hover:text-fg" />
            </RouterLink>
        </div>
        <UiEmpty v-else-if="reports" :title="t('reports.none')" />
    </div>
</template>
