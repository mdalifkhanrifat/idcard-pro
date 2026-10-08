<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Pencil, Plus, RotateCcw, Search, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiSortHeader from '@/components/ui/UiSortHeader.vue';
import MasterDataFormDialog from './MasterDataFormDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useListQuery } from '@/composables/useListQuery';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { MasterDataItem, MasterDataType } from '@/types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const types = ref<MasterDataType[]>([]);
const currentType = computed(() => String(route.params.type || types.value[0]?.type || 'department'));
const definition = computed(() => types.value.find((d) => d.type === currentType.value));
const parents = ref<MasterDataItem[]>([]);

const list = useListQuery<MasterDataItem>(() => `/master-data/${currentType.value}`, {
    filters: { search: '', is_active: '', parent: '' },
    sort: 'sort_order',
});
const { filters, rows, meta, loading, sort, page } = list;

const dialogOpen = ref(false);
const editing = ref<MasterDataItem | null>(null);

async function loadTypes(): Promise<void> {
    types.value = (await http.get('/master-data')).data.data;
}

async function loadParents(): Promise<void> {
    const parentType = definition.value?.parent;
    parents.value = parentType ? (await http.get(`/master-data/${parentType}`, { params: { per_page: 'all' } })).data.data : [];
}

async function refresh(): Promise<void> {
    await Promise.all([list.load(), loadTypes()]);
}

onMounted(async () => {
    await loadTypes();
    if (!route.params.type && types.value.length) {
        await router.replace({ name: 'master-data', params: { type: types.value[0].type } });
    }
    await Promise.all([list.load(), loadParents()]);
});

watch(currentType, async () => {
    list.resetFilters();
    page.value = 1;
    await Promise.all([list.load(), loadParents()]);
});

function openForm(item: MasterDataItem | null): void {
    editing.value = item;
    dialogOpen.value = true;
}

async function remove(item: MasterDataItem): Promise<void> {
    if (!(await confirm({ title: t('masterData.deleteConfirm', { name: item.name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/master-data/${currentType.value}/${item.id}`);
        ui.notify(t('common.deleted'));
        await refresh();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('masterData.title')" :subtitle="t('masterData.subtitle')">
            <UiButton v-if="auth.can('master_data.create') && definition" @click="openForm(null)"><Plus class="size-4" />{{ t('masterData.new') }}</UiButton>
        </UiPageHeader>

        <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
            <!-- Types -->
            <nav class="h-fit rounded-xl border border-line bg-surface p-2" aria-label="Lists">
                <RouterLink
                    v-for="item in types"
                    :key="item.type"
                    :to="{ name: 'master-data', params: { type: item.type } }"
                    class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm transition-colors"
                    :class="item.type === currentType ? 'bg-brand/10 font-medium text-brand-ink' : 'text-muted hover:bg-surface-2 hover:text-fg'"
                >
                    <span class="truncate">{{ t(`masterData.types.${item.type}`) }}</span>
                    <span class="rounded-full bg-surface-2 px-2 text-xs tabular-nums text-muted">{{ item.count }}</span>
                </RouterLink>
            </nav>

            <!-- Items -->
            <div class="min-w-0 rounded-xl border border-line bg-surface">
                <div class="flex flex-col gap-3 border-b border-line p-4 md:flex-row md:items-end">
                    <div class="relative flex-1">
                        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                        <input
                            v-model="filters.search"
                            type="search"
                            :placeholder="t('masterData.searchPlaceholder')"
                            class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                        />
                    </div>
                    <UiSelect
                        v-if="definition?.parent"
                        v-model="filters.parent"
                        class="md:w-56"
                        :placeholder="`${t(`masterData.types.${definition.parent}`)}: ${t('common.all')}`"
                        :options="parents.map((p) => ({ value: p.id, label: p.name }))"
                    />
                    <UiSelect
                        v-model="filters.is_active"
                        class="md:w-40"
                        :placeholder="`${t('common.status')}: ${t('common.all')}`"
                        :options="[{ value: '1', label: t('common.active') }, { value: '0', label: t('common.inactive') }]"
                    />
                    <UiButton v-if="filters.search || filters.is_active || filters.parent" variant="ghost" @click="list.resetFilters()">
                        <RotateCcw class="size-4" />{{ t('common.reset') }}
                    </UiButton>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs font-medium tracking-wide text-muted uppercase">
                            <tr class="border-b border-line">
                                <th class="px-5 py-3"><UiSortHeader field="name" :sort="sort" :label="t('masterData.name')" @toggle="list.toggleSort" /></th>
                                <th v-if="definition?.has_code" class="px-5 py-3"><UiSortHeader field="code" :sort="sort" :label="t('masterData.code')" @toggle="list.toggleSort" /></th>
                                <th v-if="definition?.parent" class="px-5 py-3">{{ t(`masterData.types.${definition.parent}`) }}</th>
                                <th class="px-5 py-3"><UiSortHeader field="sort_order" :sort="sort" :label="t('masterData.sortOrder')" @toggle="list.toggleSort" /></th>
                                <th class="px-5 py-3">{{ t('common.status') }}</th>
                                <th class="px-5 py-3 text-right">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody :class="loading && 'opacity-50'">
                            <tr v-for="item in rows" :key="item.id" class="border-b border-line last:border-0 hover:bg-surface-2/60">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-fg">{{ item.name }}</p>
                                    <p v-if="item.name_local || item.children_count" class="text-xs text-muted">
                                        {{ item.name_local }}<template v-if="item.name_local && item.children_count"> · </template>
                                        <template v-if="item.children_count">{{ t('masterData.children', { count: item.children_count }) }}</template>
                                    </p>
                                </td>
                                <td v-if="definition?.has_code" class="px-5 py-3 font-mono text-xs text-muted">{{ item.code ?? '—' }}</td>
                                <td v-if="definition?.parent" class="px-5 py-3 text-muted">{{ item.parent?.name ?? '—' }}</td>
                                <td class="px-5 py-3 tabular-nums text-muted">{{ item.sort_order }}</td>
                                <td class="px-5 py-3">
                                    <UiBadge :tone="item.is_active ? 'success' : 'neutral'">{{ item.is_active ? t('common.active') : t('common.inactive') }}</UiBadge>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex justify-end gap-1">
                                        <button v-if="auth.can('master_data.update')" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('common.edit')" @click="openForm(item)">
                                            <Pencil class="size-4" />
                                        </button>
                                        <button v-if="auth.can('master_data.delete')" class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger" :aria-label="t('common.delete')" @click="remove(item)">
                                            <Trash2 class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <UiEmpty v-if="!loading && !rows.length" :title="t('common.noResults')" :hint="t('common.noResultsHint')" />
                </div>
                <UiPagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :from="meta.from" :to="meta.to" @change="page = $event" />
            </div>
        </div>

        <MasterDataFormDialog v-if="definition" v-model:open="dialogOpen" :definition="definition" :item="editing" :parents="parents" @saved="refresh" />
    </div>
</template>
