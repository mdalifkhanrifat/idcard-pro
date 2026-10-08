<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { useI18n } from 'vue-i18n';
import { Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import SignatoryDialog from './SignatoryDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { http, toApiError } from '@/lib/http';
import { formatDate } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { Signatory } from '@/types';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const rows = ref<Signatory[]>([]);
const loading = ref(true);
const search = ref('');
const isActive = ref('');
const dialogOpen = ref(false);
const editing = ref<Signatory | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params: Record<string, string> = {};
        if (search.value) {
            params['filter[search]'] = search.value;
        }
        if (isActive.value) {
            params['filter[is_active]'] = isActive.value;
        }
        rows.value = (await http.get('/signatories', { params })).data.data;
    } finally {
        loading.value = false;
    }
}

watchDebounced([search, isActive], load, { debounce: 300 });
onMounted(load);

function openForm(signatory: Signatory | null): void {
    editing.value = signatory;
    dialogOpen.value = true;
}

async function remove(signatory: Signatory): Promise<void> {
    if (!(await confirm({ title: t('signatories.deleteConfirm', { name: signatory.name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/signatories/${signatory.id}`);
        ui.notify(t('common.deleted'));
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('signatories.title')" :subtitle="t('signatories.subtitle')">
            <UiButton v-if="auth.can('signatories.create')" @click="openForm(null)"><Plus class="size-4" />{{ t('signatories.new') }}</UiButton>
        </UiPageHeader>

        <div class="rounded-xl border border-line bg-surface">
            <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('signatories.searchPlaceholder')"
                        class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                    />
                </div>
                <UiSelect
                    v-model="isActive"
                    class="sm:w-48"
                    :placeholder="`${t('common.status')}: ${t('common.all')}`"
                    :options="[{ value: '1', label: t('common.active') }, { value: '0', label: t('common.inactive') }]"
                />
            </div>
            <div v-if="rows.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs font-medium tracking-wide whitespace-nowrap text-muted uppercase">
                        <tr class="border-b border-line">
                            <th class="px-5 py-3">{{ t('signatories.name') }}</th>
                            <th class="px-5 py-3">{{ t('signatories.signature') }}</th>
                            <th class="px-5 py-3">{{ t('signatories.authorityType') }}</th>
                            <th class="px-5 py-3">{{ t('signatories.period') }}</th>
                            <th class="px-5 py-3">{{ t('common.status') }}</th>
                            <th class="px-5 py-3 text-right">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody :class="loading && 'opacity-50'">
                        <tr v-for="row in rows" :key="row.id" class="border-b border-line last:border-0">
                            <td class="px-5 py-3">
                                <p class="font-medium text-fg">{{ row.name }}</p>
                                <p class="text-xs text-muted">{{ row.designation }}<template v-if="row.name_local"> · {{ row.name_local }}</template></p>
                            </td>
                            <td class="px-5 py-3">
                                <img v-if="row.signature_url" :src="row.signature_url" alt="" class="h-10 max-w-40 rounded border border-line bg-white object-contain p-0.5" />
                                <span v-else class="text-xs text-muted">{{ t('signatories.noSignature') }}</span>
                            </td>
                            <td class="px-5 py-3 text-muted">{{ row.authority_type?.name ?? '—' }}</td>
                            <td class="px-5 py-3 whitespace-nowrap text-muted">
                                <template v-if="row.effective_from || row.effective_to">{{ formatDate(row.effective_from) }} – {{ formatDate(row.effective_to) }}</template>
                                <template v-else>{{ t('signatories.always') }}</template>
                            </td>
                            <td class="px-5 py-3">
                                <UiBadge :tone="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('common.active') : t('common.inactive') }}</UiBadge>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-1">
                                    <button v-if="auth.can('signatories.update')" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('common.edit')" @click="openForm(row)"><Pencil class="size-4" /></button>
                                    <button v-if="auth.can('signatories.delete')" class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger" :aria-label="t('common.delete')" @click="remove(row)"><Trash2 class="size-4" /></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <UiEmpty v-else-if="!loading" :title="t('signatories.empty')" />
        </div>

        <SignatoryDialog v-model:open="dialogOpen" :signatory="editing" @saved="load" />
    </div>
</template>
