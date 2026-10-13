<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowDown, ArrowUp, Search, X } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import { toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import { saveOrganizationColumns, type ReportColumn, type ReportDefinition } from './reports';

/**
 * Which columns a report shows, and in what order: for this viewer (kept in this browser), or, with
 * settings.update, as the organization's default for everybody.
 */
const props = defineProps<{ report: ReportDefinition; chosen: string[] }>();
const emit = defineEmits<{ apply: [keys: string[] | null]; saved: [report: ReportDefinition] }>();
const open = defineModel<boolean>('open', { default: false });

const { t, te, n } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const canSaveDefault = computed(() => auth.can('settings.update'));

const draft = ref<string[]>([]);
const search = ref('');
const saving = ref(false);
watch(open, (isOpen) => {
    if (isOpen) {
        draft.value = [...props.chosen];
        search.value = '';
    }
});

const byKey = computed(() => new Map(props.report.columns.map((column) => [column.key, column])));
/** The server names each group; an older answer falls back to the app's own names. */
const groupLabel = (group: string) => props.report.groups?.[group] ?? (te(`reports.groups.${group}`) ? t(`reports.groups.${group}`) : group);
const groups = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase();
    const result = new Map<string, ReportColumn[]>();
    for (const column of props.report.columns) {
        if (needle && !column.label.toLocaleLowerCase().includes(needle)) {
            continue;
        }
        result.set(column.group, [...(result.get(column.group) ?? []), column]);
    }

    return [...result.entries()].map(([group, columns]) => ({ group, columns }));
});
const sameAs = (a: string[], b: string[]) => a.length === b.length && a.every((key, index) => key === b[index]);
const isOrganizationDefault = computed(() => sameAs(draft.value, props.report.default_columns));

function toggle(key: string): void {
    draft.value = draft.value.includes(key) ? draft.value.filter((existing) => existing !== key) : [...draft.value, key];
}

function move(index: number, step: -1 | 1): void {
    const next = [...draft.value];
    const [moved] = next.splice(index, 1);
    next.splice(index + step, 0, moved);
    draft.value = next;
}

function apply(): void {
    // The organization's default is not kept as an own choice, so later changes to it reach this viewer
    emit('apply', isOrganizationDefault.value ? null : draft.value);
    open.value = false;
}

async function saveDefault(columns: string[] | null): Promise<void> {
    saving.value = true;
    try {
        const report = await saveOrganizationColumns(props.report.key, columns);
        emit('saved', report);
        emit('apply', null);
        ui.notify(columns ? t('reports.columns.savedDefault') : t('reports.columns.resetDefault'));
        open.value = false;
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <UiDialog v-model:open="open" :title="t('reports.columns.title')" :description="t('reports.columns.description')" size="xl">
        <div class="grid gap-5 md:grid-cols-2">
            <!-- Every column, by where it comes from -->
            <div class="min-w-0">
                <div class="relative mb-3">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('reports.columns.search')"
                        class="h-9 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                        data-column-search
                    />
                </div>
                <div class="max-h-[50vh] space-y-4 overflow-y-auto pr-1" data-column-groups>
                    <section v-for="{ group, columns } in groups" :key="group">
                        <h3 class="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">{{ groupLabel(group) }}</h3>
                        <label v-for="column in columns" :key="column.key" class="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-fg hover:bg-surface-2" :data-column-option="column.key">
                            <input type="checkbox" class="size-4 accent-[var(--brand)]" :checked="draft.includes(column.key)" @change="toggle(column.key)" />
                            <span class="min-w-0 flex-1 truncate">{{ column.label }}</span>
                        </label>
                    </section>
                    <p v-if="!groups.length" class="text-sm text-muted">{{ t('reports.columns.noMatch') }}</p>
                </div>
            </div>

            <!-- Chosen, in order -->
            <div class="min-w-0">
                <h3 class="mb-2 text-sm font-medium text-fg">{{ t('reports.columns.chosen', { count: n(draft.length) }) }}</h3>
                <ol class="max-h-[50vh] space-y-1 overflow-y-auto" data-chosen-columns>
                    <li v-for="(key, index) in draft" :key="key" class="flex items-center gap-1 rounded-md border border-line bg-surface-2/50 py-1 pr-1 pl-3 text-sm" :data-chosen-column="key">
                        <span class="min-w-0 flex-1 truncate text-fg">{{ byKey.get(key)?.label ?? key }}</span>
                        <button type="button" class="rounded p-1 text-muted hover:bg-surface hover:text-fg disabled:opacity-30" :disabled="index === 0" :aria-label="t('reports.columns.moveUp')" :title="t('reports.columns.moveUp')" @click="move(index, -1)"><ArrowUp class="size-4" /></button>
                        <button type="button" class="rounded p-1 text-muted hover:bg-surface hover:text-fg disabled:opacity-30" :disabled="index === draft.length - 1" :aria-label="t('reports.columns.moveDown')" :title="t('reports.columns.moveDown')" @click="move(index, 1)"><ArrowDown class="size-4" /></button>
                        <button type="button" class="rounded p-1 text-muted hover:bg-surface hover:text-danger" :aria-label="t('reports.columns.remove')" :title="t('reports.columns.remove')" @click="toggle(key)"><X class="size-4" /></button>
                    </li>
                </ol>
                <p v-if="!draft.length" class="text-sm text-danger" data-no-columns>{{ t('reports.columns.none') }}</p>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <button v-if="!isOrganizationDefault" type="button" class="font-medium text-fg underline underline-offset-2 hover:no-underline" data-use-default @click="draft = [...report.default_columns]">
                        {{ t('reports.columns.useDefault') }}
                    </button>
                    <button
                        v-if="canSaveDefault && !sameAs(report.default_columns, report.standard_columns)"
                        type="button"
                        class="font-medium text-muted underline underline-offset-2 hover:text-fg hover:no-underline disabled:opacity-50"
                        :disabled="saving"
                        data-reset-org-default
                        @click="saveDefault(null)"
                    >
                        {{ t('reports.columns.resetOrgDefault') }}
                    </button>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:items-center">
                <UiButton v-if="canSaveDefault" variant="secondary" :disabled="!draft.length || isOrganizationDefault" :loading="saving" data-save-org-default @click="saveDefault(draft)">
                    {{ t('reports.columns.saveOrgDefault') }}
                </UiButton>
                <div class="flex gap-2 sm:ml-auto">
                    <UiButton variant="ghost" class="flex-1 sm:flex-none" @click="open = false">{{ t('common.cancel') }}</UiButton>
                    <UiButton class="flex-1 sm:flex-none" :disabled="!draft.length" data-apply-columns @click="apply">{{ t('reports.columns.apply') }}</UiButton>
                </div>
            </div>
        </template>
    </UiDialog>
</template>
