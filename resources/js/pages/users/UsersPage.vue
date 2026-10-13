<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ListPagination from '@/components/ListPagination.vue';
import { Lock, Pencil, Plus, RotateCcw, Search, ShieldCheck, SlidersHorizontal, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiSortHeader from '@/components/ui/UiSortHeader.vue';
import UserFormDialog from './UserFormDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useListQuery } from '@/composables/useListQuery';
import { http, toApiError } from '@/lib/http';
import { formatRelative } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { User } from '@/types';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const list = useListQuery<User>('/users', {
    filters: { search: '', status: '', role: '', two_factor: '', locked: '', created_from: '', created_to: '' },
    sort: '-created_at',
});
const { filters, rows, meta, loading, sort, page } = list;

const roles = ref<string[]>([]);
const showFilters = ref(false);
const dialogOpen = ref(false);
const editing = ref<User | null>(null);

const activeFilterCount = computed(() => Object.entries(filters).filter(([key, v]) => key !== 'search' && v !== '').length);

onMounted(async () => {
    list.load();
    if (auth.can('roles.view')) {
        const { data } = await http.get('/roles');
        roles.value = data.data.map((r: { name: string }) => r.name);
    }
});

function openCreate(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(user: User): void {
    editing.value = user;
    dialogOpen.value = true;
}

async function remove(user: User): Promise<void> {
    if (!(await confirm({ title: t('common.areYouSure'), message: t('users.deleteConfirm', { name: user.name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/users/${user.id}`);
        ui.notify(t('common.deleted'));
        list.load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}

const yesNo = computed(() => [
    { value: '1', label: t('common.yes') },
    { value: '0', label: t('common.no') },
]);
</script>

<template>
    <div>
        <UiPageHeader :title="t('users.title')" :subtitle="t('users.subtitle')">
            <UiButton v-if="auth.can('users.create')" @click="openCreate"><Plus class="size-4" />{{ t('users.new') }}</UiButton>
        </UiPageHeader>

        <div class="rounded-xl border border-line bg-surface">
            <!-- Toolbar -->
            <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('users.searchPlaceholder')"
                        class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                    />
                </div>
                <UiButton variant="secondary" @click="showFilters = !showFilters">
                    <SlidersHorizontal class="size-4" />{{ t('common.filters') }}
                    <UiBadge v-if="activeFilterCount" tone="brand">{{ activeFilterCount }}</UiBadge>
                </UiButton>
                <UiButton v-if="activeFilterCount || filters.search" variant="ghost" @click="list.resetFilters()"><RotateCcw class="size-4" />{{ t('common.reset') }}</UiButton>
            </div>
            <div v-if="showFilters" class="grid gap-4 border-b border-line bg-surface-2/50 p-4 sm:grid-cols-3 lg:grid-cols-6">
                <UiSelect v-model="filters.status" :label="t('common.status')" :placeholder="t('common.all')" :options="[{ value: 'active', label: t('common.active') }, { value: 'inactive', label: t('common.inactive') }]" />
                <UiSelect v-model="filters.role" :label="t('users.filterRole')" :placeholder="t('common.all')" :options="roles.map((r) => ({ value: r, label: r }))" />
                <UiSelect v-model="filters.two_factor" :label="t('users.filterTwoFactor')" :placeholder="t('common.all')" :options="yesNo" />
                <UiSelect v-model="filters.locked" :label="t('users.filterLocked')" :placeholder="t('common.all')" :options="yesNo" />
                <UiInput v-model="filters.created_from" type="date" :label="`${t('users.created')} · ${t('common.from')}`" />
                <UiInput v-model="filters.created_to" type="date" :label="`${t('users.created')} · ${t('common.to')}`" />
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs font-medium tracking-wide text-muted uppercase">
                        <tr class="border-b border-line">
                            <th class="px-5 py-3"><UiSortHeader field="name" :sort="sort" :label="t('users.name')" @toggle="list.toggleSort" /></th>
                            <th class="px-5 py-3">{{ t('users.roles') }}</th>
                            <th class="px-5 py-3">{{ t('common.status') }}</th>
                            <th class="px-5 py-3"><UiSortHeader field="last_login_at" :sort="sort" :label="t('users.lastLogin')" @toggle="list.toggleSort" /></th>
                            <th class="px-5 py-3 text-right">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody :class="loading && 'opacity-50'">
                        <tr v-for="user in rows" :key="user.id" class="border-b border-line last:border-0 hover:bg-surface-2/60">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand/12 text-xs font-semibold text-brand-ink">
                                        {{ user.name.split(' ').map((p) => p[0]).slice(0, 2).join('') }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-fg">{{ user.name }}</p>
                                        <p class="truncate text-xs text-muted">{{ user.email }}<template v-if="user.username"> · @{{ user.username }}</template></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap gap-1"><UiBadge v-for="role in user.roles" :key="role">{{ role }}</UiBadge></div>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <UiBadge :tone="user.status === 'active' ? 'success' : 'neutral'">{{ t(`common.${user.status}`) }}</UiBadge>
                                    <UiBadge v-if="user.is_locked" tone="danger"><Lock class="size-3" />{{ t('users.locked') }}</UiBadge>
                                    <UiBadge v-if="user.two_factor_enabled" tone="brand"><ShieldCheck class="size-3" />{{ t('users.twoFactor') }}</UiBadge>
                                </div>
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap text-muted">{{ user.last_login_at ? formatRelative(user.last_login_at) : t('common.never') }}</td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-1">
                                    <button v-if="auth.can('users.update')" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('common.edit')" @click="openEdit(user)">
                                        <Pencil class="size-4" />
                                    </button>
                                    <button
                                        v-if="auth.can('users.delete') && user.id !== auth.user?.id"
                                        class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger"
                                        :aria-label="t('common.delete')"
                                        @click="remove(user)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <UiEmpty v-if="!loading && !rows.length" :title="t('common.noResults')" :hint="t('common.noResultsHint')" />
            </div>
            <ListPagination :list="list" />
        </div>

        <UserFormDialog v-model:open="dialogOpen" :user="editing" :roles="roles" @saved="list.load()" />
    </div>
</template>
