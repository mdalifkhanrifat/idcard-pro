<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import FamilyMemberDialog from './FamilyMemberDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { http, toApiError } from '@/lib/http';
import { formatDate } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { FamilyMember } from '@/types';

const props = defineProps<{ cardholderId: string }>();
const { t, n } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const members = ref<FamilyMember[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<FamilyMember | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        members.value = (await http.get(`/cardholders/${props.cardholderId}/family-members`)).data.data;
    } finally {
        loading.value = false;
    }
}

function age(dateOfBirth: string | null): number | null {
    if (!dateOfBirth) {
        return null;
    }
    const born = new Date(dateOfBirth);
    const now = new Date();
    const years = now.getFullYear() - born.getFullYear();

    return now < new Date(now.getFullYear(), born.getMonth(), born.getDate()) ? years - 1 : years;
}

function openForm(member: FamilyMember | null): void {
    editing.value = member;
    dialogOpen.value = true;
}

async function remove(member: FamilyMember): Promise<void> {
    if (!(await confirm({ title: t('cardholders.family.deleteConfirm', { name: member.name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/cardholders/${props.cardholderId}/family-members/${member.id}`);
        ui.notify(t('common.deleted'));
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}

onMounted(load);
</script>

<template>
    <div class="rounded-xl border border-line bg-surface">
        <div class="flex items-center justify-between gap-4 border-b border-line px-5 py-4">
            <h2 class="text-base font-semibold text-fg">{{ t('cardholders.family.title') }}</h2>
            <UiButton v-if="auth.can('cardholders.update')" size="sm" @click="openForm(null)"><Plus class="size-4" />{{ t('cardholders.family.add') }}</UiButton>
        </div>
        <div v-if="members.length" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs font-medium tracking-wide whitespace-nowrap text-muted uppercase">
                    <tr class="border-b border-line">
                        <th class="px-5 py-3">{{ t('cardholders.family.name') }}</th>
                        <th class="px-5 py-3">{{ t('cardholders.family.relation') }}</th>
                        <th class="px-5 py-3">{{ t('cardholders.fields.date_of_birth') }}</th>
                        <th class="px-5 py-3">{{ t('cardholders.family.phone') }}</th>
                        <th class="px-5 py-3">{{ t('cardholders.fields.national_id') }}</th>
                        <th class="px-5 py-3">{{ t('cardholders.family.cardNo') }}</th>
                        <th class="px-5 py-3 text-right">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="member in members" :key="member.id" class="border-b border-line last:border-0">
                        <td class="px-5 py-3">
                            <p class="font-medium text-fg">{{ member.name }}</p>
                            <p v-if="member.name_local" class="text-xs text-muted">{{ member.name_local }}</p>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex flex-wrap gap-1">
                                <UiBadge tone="brand">{{ t(`cardholders.family.relations.${member.relation}`) }}</UiBadge>
                                <UiBadge v-if="member.blood_group" tone="danger">{{ member.blood_group.name }}</UiBadge>
                            </div>
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap text-muted">
                            {{ formatDate(member.date_of_birth) }}
                            <span v-if="age(member.date_of_birth) !== null" class="block text-xs">{{ t('cardholders.family.age', { years: n(age(member.date_of_birth) ?? 0) }) }}</span>
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap text-muted">{{ member.phone ?? '—' }}</td>
                        <td class="px-5 py-3 whitespace-nowrap text-muted">{{ member.national_id ?? member.passport_no ?? '—' }}</td>
                        <td class="px-5 py-3 font-mono text-xs text-muted">{{ member.card_no ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <div v-if="auth.can('cardholders.update')" class="flex justify-end gap-1">
                                <button class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('common.edit')" @click="openForm(member)"><Pencil class="size-4" /></button>
                                <button class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger" :aria-label="t('common.delete')" @click="remove(member)"><Trash2 class="size-4" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <UiEmpty v-else-if="!loading" :title="t('cardholders.family.empty')" />

        <FamilyMemberDialog v-model:open="dialogOpen" :cardholder-id="cardholderId" :member="editing" @saved="load" />
    </div>
</template>
