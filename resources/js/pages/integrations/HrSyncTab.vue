<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, Check, Copy, Plus, RotateCcw, Trash2 } from 'lucide-vue-next';
import ListPagination from '@/components/ListPagination.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useListQuery } from '@/composables/useListQuery';
import { formatDateTime, formatNumber } from '@/lib/format';
import { toApiError } from '@/lib/http';
import {
    getSyncFields,
    getSyncSettings,
    mapFromRows,
    rowsFromMap,
    runTone,
    sampleRequest,
    saveSyncSettings,
    type FieldNameRow,
    type SyncField,
    type SyncRun,
    type SyncSettingsAnswer,
} from '@/lib/integrations/sync';
import { useUiStore } from '@/stores/ui';

/**
 * The HR sync (6.3), for whoever manages integrations: how an HR system sends people, the organization's own HR field
 * names, the runs, and how long the run log is kept. The settings are the organization's: only someone who may change
 * them can (the server says so).
 */
const { t } = useI18n();
const ui = useUiStore();

const runs = useListQuery<SyncRun>('/sync/cardholders/runs', { filters: { kind: '' }, queryPrefix: 'sync_' });
const kinds = computed(() => ((runs.meta.value as { kinds?: SyncRun['kind'][] }).kinds ?? []).map((kind) => ({ value: kind, label: t(`integrations.sync.kinds.${kind}`) })));

const fields = ref<SyncField[]>([]);
const answer = ref<SyncSettingsAnswer | null>(null);
const canUpdate = computed(() => answer.value?.meta.can_update ?? false);
const names = ref<FieldNameRow[]>([]);
const keep = reactive({ sync_prune_log: false, sync_log_days: 365 as number | string });
const errors = ref<Record<string, string[]>>({});
const saving = ref<'names' | 'keep' | null>(null);
const copied = ref<SyncRun['kind'] | null>(null);

const fieldOptions = computed(() => fields.value.map((field) => ({ value: field.field, label: `${field.label} (${field.field})` })));
const fieldLabel = (field: string): string => fields.value.find((known) => known.field === field)?.label ?? field;
const savedMap = computed(() => answer.value?.data.sync_fields ?? {});
const samples = computed(() => (['upsert', 'deactivate'] as const).map((kind) => ({ kind, text: sampleRequest(window.location.origin, kind, savedMap.value) })));

function take(settings: SyncSettingsAnswer): void {
    answer.value = settings;
    names.value = rowsFromMap(settings.data.sync_fields);
    keep.sync_prune_log = settings.data.sync_prune_log;
    keep.sync_log_days = settings.data.sync_log_days;
}

onMounted(async () => {
    runs.load();
    try {
        const [settings, known] = await Promise.all([getSyncSettings(), getSyncFields()]);
        fields.value = known;
        take(settings);
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
});

async function save(part: 'names' | 'keep'): Promise<void> {
    saving.value = part;
    errors.value = {};
    try {
        take(await saveSyncSettings(part === 'names' ? { sync_fields: mapFromRows(names.value) } : { sync_prune_log: keep.sync_prune_log, sync_log_days: Number(keep.sync_log_days) }));
        ui.notify(t('common.saved'));
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.errors;
        if (!Object.keys(apiError.errors).length) {
            ui.notify(apiError.message, 'error');
        }
    } finally {
        saving.value = null;
    }
}

async function copy(kind: SyncRun['kind'], text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = kind;
    } catch {
        ui.notify(t('integrations.sync.copyFailed'), 'error');
    }
}

/** What a run did, as badges: only the results it had. */
function counts(run: SyncRun): { key: string; tone: 'success' | 'neutral' | 'warning' | 'danger' | 'brand'; text: string }[] {
    const updated = run.kind === 'deactivate' ? 'deactivated' : 'updated';
    return [
        { key: 'created', tone: 'success' as const, count: run.created },
        { key: updated, tone: 'brand' as const, count: run.updated },
        { key: 'unchanged', tone: 'neutral' as const, count: run.unchanged },
        { key: 'notFound', tone: 'warning' as const, count: run.not_found },
        { key: 'failed', tone: 'danger' as const, count: run.failed },
    ]
        .filter((item) => item.count > 0)
        .map((item) => ({ key: item.key, tone: item.tone, text: t(`integrations.sync.${item.key}`, { count: formatNumber(item.count) }, item.count) }));
}
</script>

<template>
    <div class="space-y-5" data-sync-tab>
        <UiCard :title="t('integrations.sync.howTitle')" :description="t('integrations.sync.howDescription')" data-sync-how>
            <p class="mb-4 text-sm text-muted">{{ t('integrations.sync.tokenHint') }}</p>
            <div class="space-y-4">
                <div v-for="sample in samples" :key="sample.kind" class="space-y-2" :data-sync-sample="sample.kind">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-medium text-fg">{{ t(`integrations.sync.${sample.kind}`) }}</p>
                        <UiButton variant="secondary" size="sm" :data-sync-copy="sample.kind" @click="copy(sample.kind, sample.text)">
                            <Check v-if="copied === sample.kind" class="size-4" /><Copy v-else class="size-4" />{{ copied === sample.kind ? t('common.copied') : t('common.copy') }}
                        </UiButton>
                    </div>
                    <pre class="overflow-x-auto rounded-lg border border-line bg-surface-2 p-3 text-xs leading-relaxed text-fg" dir="ltr"><code>{{ sample.text }}</code></pre>
                </div>
            </div>
        </UiCard>

        <UiCard :title="t('integrations.sync.namesTitle')" :description="t('integrations.sync.namesDescription')" data-sync-names>
            <template v-if="canUpdate">
                <div class="space-y-3">
                    <div v-for="(row, index) in names" :key="index" class="grid gap-2 rounded-lg border border-line p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" data-sync-name-row>
                        <UiInput v-model="row.theirs" :label="t('integrations.sync.theirs')" :maxlength="100" dir="ltr" data-sync-name-theirs />
                        <UiSelect v-model="row.ours" :label="t('integrations.sync.ours')" placeholder="—" :options="fieldOptions" data-sync-name-ours />
                        <button type="button" class="justify-self-start rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger sm:mb-0.5" :aria-label="t('integrations.sync.removeName')" :title="t('integrations.sync.removeName')" data-sync-name-remove @click="names.splice(index, 1)">
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                    <p v-if="!names.length" class="text-sm text-muted" data-sync-no-names>{{ t('integrations.sync.noNames') }}</p>
                    <p v-if="errors.sync_fields" class="text-sm text-danger" role="alert" data-sync-names-error>{{ errors.sync_fields[0] }}</p>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <UiButton variant="secondary" data-sync-name-add @click="names.push({ theirs: '', ours: '' })"><Plus class="size-4" />{{ t('integrations.sync.addName') }}</UiButton>
                    <UiButton variant="ghost" data-sync-name-reset @click="names = rowsFromMap(savedMap)"><RotateCcw class="size-4" />{{ t('common.reset') }}</UiButton>
                    <UiButton class="ml-auto" :loading="saving === 'names'" data-sync-names-save @click="save('names')">{{ t('common.save') }}</UiButton>
                </div>
            </template>
            <template v-else>
                <dl v-if="names.length" class="divide-y divide-line text-sm">
                    <div v-for="row in names" :key="row.theirs" class="flex flex-wrap justify-between gap-2 py-2" data-sync-name-row>
                        <dt class="font-mono text-fg" dir="ltr">{{ row.theirs }}</dt>
                        <dd class="text-muted">{{ fieldLabel(row.ours) }}</dd>
                    </div>
                </dl>
                <p v-else class="text-sm text-muted" data-sync-no-names>{{ t('integrations.sync.noNames') }}</p>
                <p class="mt-3 text-xs text-muted" data-sync-read-only>{{ t('integrations.sync.readOnly') }}</p>
            </template>
        </UiCard>

        <UiCard :title="t('integrations.sync.runsTitle')" :description="t('integrations.sync.runsDescription')" :padded="false" data-sync-runs>
            <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-end">
                <UiSelect v-model="runs.filters.kind" :label="t('integrations.sync.kind')" :placeholder="t('common.all')" :options="kinds" data-sync-kind />
            </div>
            <ul class="divide-y divide-line" :class="runs.loading.value && 'opacity-50'" data-sync-run-list>
                <li v-for="run in runs.rows.value" :key="run.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 text-sm" data-sync-run>
                    <div class="min-w-48 flex-1">
                        <p class="font-medium text-fg">
                            {{ t(`integrations.sync.kinds.${run.kind}`) }}
                            <span class="font-normal text-muted">· {{ t('integrations.sync.total', { count: formatNumber(run.total) }, run.total) }}</span>
                        </p>
                        <p class="text-xs text-muted">
                            {{ formatDateTime(run.started_at) }}
                            <template v-if="run.account"> · <span dir="auto">{{ run.account.deleted ? t('integrations.sync.deletedAccount', { name: run.account.name }) : run.account.name }}</span></template>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <UiBadge v-if="run.finished_at === null" :tone="runTone(run)" data-sync-broke-off>{{ t('integrations.sync.brokeOff') }}</UiBadge>
                        <UiBadge v-for="item in counts(run)" :key="item.key" :tone="item.tone">{{ item.text }}</UiBadge>
                    </div>
                </li>
            </ul>
            <UiEmpty v-if="!runs.loading.value && !runs.rows.value.length" :title="t('integrations.sync.noRuns')" :hint="t('integrations.sync.noRunsHint')" data-sync-no-runs />
            <ListPagination :list="runs" />
        </UiCard>

        <UiCard :title="t('integrations.sync.keepTitle')" :description="t('integrations.sync.keepDescription')" data-sync-keep>
            <fieldset class="space-y-4" :disabled="!canUpdate">
                <UiCheckbox v-model="keep.sync_prune_log" :label="t('integrations.sync.pruneLog')" :description="keep.sync_prune_log ? '' : t('integrations.sync.keepHint')" data-sync-prune />
                <div v-if="keep.sync_prune_log" class="space-y-3 rounded-lg border border-warning/40 bg-warning/8 p-3" data-sync-prune-warning>
                    <p class="flex items-start gap-2 text-sm text-fg"><AlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />{{ t('integrations.sync.pruneWarning') }}</p>
                    <UiInput
                        v-model="keep.sync_log_days"
                        type="number"
                        :min="String(answer?.meta.log_days.min ?? 7)"
                        :max="String(answer?.meta.log_days.max ?? 3650)"
                        :label="t('integrations.sync.logDays')"
                        :hint="t('integrations.sync.logDaysHint', { min: formatNumber(answer?.meta.log_days.min ?? 7), max: formatNumber(answer?.meta.log_days.max ?? 3650) })"
                        :error="errors.sync_log_days"
                        data-sync-log-days
                    />
                </div>
            </fieldset>
            <p v-if="!canUpdate" class="mt-3 text-xs text-muted">{{ t('integrations.sync.readOnly') }}</p>
            <div v-else class="mt-4 flex">
                <UiButton class="ml-auto" :loading="saving === 'keep'" data-sync-keep-save @click="save('keep')">{{ t('common.save') }}</UiButton>
            </div>
        </UiCard>
    </div>
</template>
