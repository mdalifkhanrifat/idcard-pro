<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import QualificationDialog from './QualificationDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { Qualification, QualificationKind } from '@/types';

const props = defineProps<{ cardholderId: string }>();
const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const records = ref<Qualification[]>([]);
const loaded = ref(false);
const dialogOpen = ref(false);
const dialogKind = ref<QualificationKind>('education');
const editing = ref<Qualification | null>(null);

const COLUMNS: Record<QualificationKind, (keyof Qualification)[]> = {
    education: ['title', 'institution', 'year', 'subject', 'degree', 'result'],
    course: ['title', 'institution', 'year', 'result', 'certificate_no'],
};
const groups = computed(() => (['education', 'course'] as const).map((kind) => ({ kind, rows: records.value.filter((r) => r.kind === kind) })));

async function load(): Promise<void> {
    records.value = (await http.get(`/cardholders/${props.cardholderId}/qualifications`)).data.data;
    loaded.value = true;
}

function openForm(kind: QualificationKind, record: Qualification | null): void {
    dialogKind.value = kind;
    editing.value = record;
    dialogOpen.value = true;
}

async function remove(record: Qualification): Promise<void> {
    if (!(await confirm({ title: t('cardholders.qualifications.deleteConfirm', { name: record.title }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/cardholders/${props.cardholderId}/qualifications/${record.id}`);
        ui.notify(t('common.deleted'));
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <section v-for="group in groups" :key="group.kind" class="rounded-xl border border-line bg-surface">
            <div class="flex items-center justify-between gap-4 border-b border-line px-5 py-4">
                <h2 class="text-base font-semibold text-fg">{{ t(`cardholders.qualifications.${group.kind}`) }}</h2>
                <UiButton v-if="auth.can('cardholders.update')" size="sm" variant="secondary" @click="openForm(group.kind, null)">
                    <Plus class="size-4" />{{ t(group.kind === 'education' ? 'cardholders.qualifications.addEducation' : 'cardholders.qualifications.addCourse') }}
                </UiButton>
            </div>
            <div v-if="group.rows.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs font-medium tracking-wide whitespace-nowrap text-muted uppercase">
                        <tr class="border-b border-line">
                            <th v-for="column in COLUMNS[group.kind]" :key="column" class="px-5 py-3">{{ t(`cardholders.qualifications.fields.${group.kind}.${column}`) }}</th>
                            <th class="px-5 py-3 text-right">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in group.rows" :key="row.id" class="border-b border-line last:border-0">
                            <td v-for="column in COLUMNS[group.kind]" :key="column" class="px-5 py-3" :class="column === 'title' ? 'font-medium text-fg' : 'text-muted'">
                                {{ row[column] ?? '—' }}
                            </td>
                            <td class="px-5 py-3">
                                <div v-if="auth.can('cardholders.update')" class="flex justify-end gap-1">
                                    <button class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('common.edit')" @click="openForm(group.kind, row)"><Pencil class="size-4" /></button>
                                    <button class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger" :aria-label="t('common.delete')" @click="remove(row)"><Trash2 class="size-4" /></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else-if="loaded" class="px-5 py-6 text-sm text-muted">
                {{ t(group.kind === 'education' ? 'cardholders.qualifications.emptyEducation' : 'cardholders.qualifications.emptyCourse') }}
            </p>
        </section>

        <QualificationDialog v-model:open="dialogOpen" :cardholder-id="cardholderId" :kind="dialogKind" :record="editing" @saved="load" />
    </div>
</template>
