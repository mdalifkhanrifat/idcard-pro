<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, CheckCircle2, Download, FileSpreadsheet, Loader2, Upload, XCircle } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import { http, toApiError } from '@/lib/http';
import { formatDateTime } from '@/lib/format';
import { useUiStore } from '@/stores/ui';
import type { CardholderImport, ImportStatus } from '@/types';

/** Keep in sync with config/cardholder_import.php. */
const LIMITS = { maxMb: 10, maxRows: 5000, maxErrors: 500 };

const { t, te } = useI18n();
const ui = useUiStore();

const file = ref<File | null>(null);
const input = ref<HTMLInputElement | null>(null);
const options = ref({ create_missing_lookups: false, update_existing: false });
const fileError = ref('');
const busy = ref(false);
const current = ref<CardholderImport | null>(null);
const history = ref<CardholderImport[]>([]);
let poll: ReturnType<typeof setTimeout> | null = null;

const running = computed(() => current.value && ['queued', 'processing'].includes(current.value.status));
const invalidRows = computed(() => (current.value ? current.value.total_rows - current.value.valid_rows : 0));
const previewColumns = computed(() => current.value?.summary?.columns ?? []);

const tone = (status: ImportStatus) => ({ analyzed: 'brand', queued: 'neutral', processing: 'warning', completed: 'success', failed: 'danger' } as const)[status];
const fieldLabel = (field: string, header: string) => (te(`cardholders.fields.${field}`) ? t(`cardholders.fields.${field}`) : header);

async function loadHistory(): Promise<void> {
    history.value = (await http.get('/cardholder-imports')).data.data;
}

function choose(event: Event): void {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    fileError.value = file.value && file.value.size > LIMITS.maxMb * 1024 * 1024 ? t('cardholders.files.tooLarge', { size: LIMITS.maxMb }) : '';
}

async function analyze(): Promise<void> {
    if (!file.value || fileError.value) {
        return;
    }
    busy.value = true;
    const body = new FormData();
    body.append('file', file.value);
    body.append('create_missing_lookups', options.value.create_missing_lookups ? '1' : '0');
    body.append('update_existing', options.value.update_existing ? '1' : '0');
    try {
        current.value = (await http.post('/cardholder-imports', body)).data.data;
        await loadHistory();
    } catch (error) {
        const apiError = toApiError(error);
        fileError.value = apiError.errors.file?.[0] ?? apiError.message;
    } finally {
        busy.value = false;
    }
}

async function refresh(): Promise<void> {
    if (!current.value) {
        return;
    }
    current.value = (await http.get(`/cardholder-imports/${current.value.id}`)).data.data;
    if (running.value) {
        poll = setTimeout(refresh, 1500);
    } else {
        await loadHistory();
    }
}

async function start(): Promise<void> {
    busy.value = true;
    try {
        current.value = (await http.post(`/cardholder-imports/${current.value!.id}/start`)).data.data;
        await refresh();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    } finally {
        busy.value = false;
    }
}

async function discard(): Promise<void> {
    try {
        await http.delete(`/cardholder-imports/${current.value!.id}`);
        ui.notify(t('cardholders.import.discarded'));
        reset();
        await loadHistory();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}

function reset(): void {
    current.value = null;
    file.value = null;
    fileError.value = '';
    if (input.value) {
        input.value.value = '';
    }
}

async function downloadTemplate(): Promise<void> {
    const { data } = await http.get('/cardholder-imports/template', { responseType: 'blob' });
    const url = URL.createObjectURL(data);
    const link = Object.assign(document.createElement('a'), { href: url, download: 'cardholders-template.csv' });
    link.click();
    URL.revokeObjectURL(url);
}

async function open(item: CardholderImport): Promise<void> {
    current.value = item;
    await refresh();
}

onMounted(loadHistory);
onBeforeUnmount(() => poll && clearTimeout(poll));
</script>

<template>
    <div>
        <RouterLink to="/cardholders" class="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-fg"><ArrowLeft class="size-4" />{{ t('cardholders.back') }}</RouterLink>
        <UiPageHeader :title="t('cardholders.import.title')" :subtitle="t('cardholders.import.subtitle')">
            <UiButton variant="secondary" @click="downloadTemplate"><Download class="size-4" />{{ t('cardholders.import.template') }}</UiButton>
        </UiPageHeader>

        <div class="space-y-6">
            <!-- Step 1: upload -->
            <UiCard v-if="!current">
                <form class="space-y-5" @submit.prevent="analyze">
                    <div>
                        <p class="mb-1.5 text-sm font-medium text-fg">{{ t('cardholders.import.file') }}</p>
                        <input
                            ref="input"
                            type="file"
                            accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            class="block w-full text-sm text-muted file:mr-3 file:h-10 file:rounded-lg file:border file:border-line file:bg-surface-2 file:px-3 file:text-fg"
                            @change="choose"
                        />
                        <p class="mt-1.5 text-xs" :class="fileError ? 'text-danger' : 'text-muted'">
                            {{ fileError || t('cardholders.import.fileHint', { size: LIMITS.maxMb, rows: LIMITS.maxRows }) }}
                        </p>
                    </div>
                    <UiCheckbox v-model="options.create_missing_lookups" :label="t('cardholders.import.createMissing')" :description="t('cardholders.import.createMissingHint')" />
                    <UiCheckbox v-model="options.update_existing" :label="t('cardholders.import.updateExisting')" :description="t('cardholders.import.updateExistingHint')" />
                    <UiButton type="submit" :loading="busy" :disabled="!file || !!fileError"><Upload class="size-4" />{{ busy ? t('cardholders.import.analyzing') : t('cardholders.import.analyze') }}</UiButton>
                </form>
            </UiCard>

            <!-- Step 2/3: analysis and result -->
            <template v-else>
                <div class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface p-4">
                    <FileSpreadsheet class="size-5 text-muted" />
                    <span class="font-medium text-fg">{{ current.original_name }}</span>
                    <UiBadge :tone="tone(current.status)">{{ t(`cardholders.import.status.${current.status}`) }}</UiBadge>
                    <span class="flex-1" />
                    <template v-if="current.status === 'analyzed'">
                        <UiButton variant="ghost" @click="discard">{{ t('cardholders.import.discard') }}</UiButton>
                        <UiButton :loading="busy" :disabled="!current.valid_rows" @click="start">{{ t('cardholders.import.start', { count: current.valid_rows }) }}</UiButton>
                    </template>
                    <UiButton v-else-if="!running" variant="secondary" @click="reset">{{ t('cardholders.import.new') }}</UiButton>
                </div>

                <div v-if="running" class="flex items-center gap-3 rounded-xl border border-line bg-surface p-5 text-sm text-muted">
                    <Loader2 class="size-5 animate-spin" />{{ t('cardholders.import.running') }}
                </div>

                <!-- Counts -->
                <div class="grid gap-4 sm:grid-cols-3">
                    <template v-if="current.status === 'completed' || current.status === 'failed'">
                        <div class="rounded-xl border border-line bg-surface p-4">
                            <p class="text-sm text-muted">{{ t('cardholders.import.created') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-success tabular-nums">{{ current.created_count }}</p>
                        </div>
                        <div class="rounded-xl border border-line bg-surface p-4">
                            <p class="text-sm text-muted">{{ t('cardholders.import.updated') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-brand-ink tabular-nums">{{ current.updated_count }}</p>
                        </div>
                        <div class="rounded-xl border border-line bg-surface p-4">
                            <p class="text-sm text-muted">{{ t('cardholders.import.failed') }}</p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums" :class="current.failed_count ? 'text-danger' : 'text-fg'">{{ current.failed_count }}</p>
                        </div>
                    </template>
                    <template v-else>
                        <div class="rounded-xl border border-line bg-surface p-4">
                            <p class="text-sm text-muted">{{ t('cardholders.import.total') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-fg tabular-nums">{{ current.total_rows }}</p>
                        </div>
                        <div class="rounded-xl border border-line bg-surface p-4">
                            <p class="flex items-center gap-1.5 text-sm text-muted"><CheckCircle2 class="size-4 text-success" />{{ t('cardholders.import.valid') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-success tabular-nums">{{ current.valid_rows }}</p>
                        </div>
                        <div class="rounded-xl border border-line bg-surface p-4">
                            <p class="flex items-center gap-1.5 text-sm text-muted"><XCircle class="size-4 text-danger" />{{ t('cardholders.import.invalid') }}</p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums" :class="invalidRows ? 'text-danger' : 'text-fg'">{{ invalidRows }}</p>
                        </div>
                    </template>
                </div>

                <!-- Mapping -->
                <UiCard v-if="current.summary && current.status === 'analyzed'" :title="t('cardholders.import.columns')">
                    <div class="flex flex-wrap gap-1.5">
                        <UiBadge v-for="column in current.summary.columns" :key="column.field" tone="brand">{{ column.header }} → {{ fieldLabel(column.field, column.header) }}</UiBadge>
                    </div>
                    <template v-if="current.summary.ignored.length">
                        <p class="mt-4 mb-1.5 text-sm font-medium text-fg">{{ t('cardholders.import.ignored') }}</p>
                        <div class="flex flex-wrap gap-1.5"><UiBadge v-for="header in current.summary.ignored" :key="header">{{ header }}</UiBadge></div>
                    </template>
                    <template v-if="current.summary.lookups_to_create.length">
                        <p class="mt-4 mb-1.5 text-sm font-medium text-fg">{{ t('cardholders.import.willCreate') }}</p>
                        <div class="flex flex-wrap gap-1.5"><UiBadge v-for="item in current.summary.lookups_to_create" :key="item" tone="warning">{{ item }}</UiBadge></div>
                    </template>
                </UiCard>

                <!-- Preview -->
                <UiCard
                    v-if="current.status === 'analyzed' && current.summary?.preview.length"
                    :title="t('cardholders.import.preview')"
                    :description="t('cardholders.import.previewHint', { count: current.summary.preview.length })"
                    :padded="false"
                >
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs font-medium tracking-wide whitespace-nowrap text-muted uppercase">
                                <tr class="border-b border-line">
                                    <th class="px-4 py-2.5">{{ t('cardholders.import.row') }}</th>
                                    <th v-for="column in previewColumns" :key="column.field" class="px-4 py-2.5">{{ column.header }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in current.summary.preview" :key="row.row" class="border-b border-line last:border-0">
                                    <td class="px-4 py-2 text-muted tabular-nums">
                                        {{ row.row }}
                                        <UiBadge v-if="row.action === 'updated'" tone="brand">{{ t('cardholders.import.willUpdate') }}</UiBadge>
                                    </td>
                                    <td v-for="column in previewColumns" :key="column.field" class="px-4 py-2 whitespace-nowrap text-fg">{{ row.values[column.field] || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </UiCard>

                <!-- Errors -->
                <UiCard v-if="current.errors.length" :title="t('cardholders.import.errors')" :padded="false">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs font-medium tracking-wide text-muted uppercase">
                            <tr class="border-b border-line">
                                <th class="w-20 px-4 py-2.5">{{ t('cardholders.import.row') }}</th>
                                <th class="px-4 py-2.5">{{ t('cardholders.import.problems') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="error in current.errors" :key="error.row" class="border-b border-line align-top last:border-0">
                                <td class="px-4 py-2 text-muted tabular-nums">{{ error.row || '—' }}</td>
                                <td class="px-4 py-2">
                                    <ul class="space-y-0.5 text-danger"><li v-for="message in error.messages" :key="message">{{ message }}</li></ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="current.errors.length >= LIMITS.maxErrors" class="border-t border-line px-4 py-2 text-xs text-muted">
                        {{ t('cardholders.import.errorsTruncated', { count: LIMITS.maxErrors }) }}
                    </p>
                </UiCard>
            </template>

            <!-- History -->
            <UiCard :title="t('cardholders.import.history')" :padded="false">
                <ul v-if="history.length" class="divide-y divide-line">
                    <li v-for="item in history" :key="item.id">
                        <button type="button" class="flex w-full flex-wrap items-center gap-3 px-5 py-3 text-left text-sm hover:bg-surface-2/60" @click="open(item)">
                            <FileSpreadsheet class="size-4 text-muted" />
                            <span class="font-medium text-fg">{{ item.original_name }}</span>
                            <UiBadge :tone="tone(item.status)">{{ t(`cardholders.import.status.${item.status}`) }}</UiBadge>
                            <span class="text-muted">
                                <template v-if="item.status === 'completed'">{{ t('cardholders.import.created') }} {{ item.created_count }} · {{ t('cardholders.import.updated') }} {{ item.updated_count }} · {{ t('cardholders.import.failed') }} {{ item.failed_count }}</template>
                                <template v-else>{{ t('cardholders.import.valid') }} {{ item.valid_rows }} / {{ item.total_rows }}</template>
                            </span>
                            <span class="flex-1" />
                            <span class="text-xs text-muted">{{ item.user?.name }} · {{ formatDateTime(item.created_at) }}</span>
                        </button>
                    </li>
                </ul>
                <p v-else class="px-5 py-6 text-sm text-muted">{{ t('cardholders.import.noHistory') }}</p>
            </UiCard>
        </div>
    </div>
</template>
