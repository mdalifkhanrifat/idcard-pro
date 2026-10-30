<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { KeyRound, Pencil, Plus, RotateCcw, Search, Trash2 } from 'lucide-vue-next';
import ListPagination from '@/components/ListPagination.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import IntegrationFormDialog from './IntegrationFormDialog.vue';
import IntegrationTokensDialog from './IntegrationTokensDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useListQuery } from '@/composables/useListQuery';
import { formatNumber, formatRelative } from '@/lib/format';
import { http, toApiError } from '@/lib/http';
import type { Integration, IntegrationOptions } from '@/lib/integrations/api';
import { useUiStore } from '@/stores/ui';

const { t } = useI18n();
const ui = useUiStore();
const { confirm } = useConfirm();

const list = useListQuery<Integration>('/integrations', { filters: { search: '', status: '', role: '' }, sort: 'name' });
const { filters, rows, meta, loading } = list;

/** What this person can give and how long a token lasts: the server's word, in the list's answer. */
const options = computed(() => (Array.isArray((meta.value as Partial<IntegrationOptions>).assignable_roles) ? (meta.value as unknown as IntegrationOptions) : undefined));
const roles = computed(() => options.value?.assignable_roles ?? []);
const formOpen = ref(false);
const tokensOpen = ref(false);
const editing = ref<Integration | null>(null);
const tokensOf = ref<Integration | null>(null);

onMounted(() => list.load());

/** Stronger than this person: they can correct its words and stop its tokens, not reach further. */
const within = (account: Integration): boolean => roles.value.includes(account.role ?? '');

function openCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(account: Integration): void {
    editing.value = account;
    formOpen.value = true;
}

function openTokens(account: Integration): void {
    tokensOf.value = account;
    tokensOpen.value = true;
}

/** A new account's next step is its first token. */
function saved(account: Integration, isNew: boolean): void {
    list.load();
    if (isNew) {
        openTokens(account);
    }
}

async function remove(account: Integration): Promise<void> {
    if (!(await confirm({ title: t('common.areYouSure'), message: t('integrations.deleteConfirm', { name: account.name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/integrations/${account.id}`);
        ui.notify(t('common.deleted'));
        list.load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('integrations.title')" :subtitle="t('integrations.subtitle')">
            <UiButton :disabled="!roles.length" data-integration-new @click="openCreate"><Plus class="size-4" />{{ t('integrations.new') }}</UiButton>
        </UiPageHeader>

        <div class="rounded-xl border border-line bg-surface">
            <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-end">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('integrations.searchPlaceholder')"
                        :aria-label="t('integrations.searchPlaceholder')"
                        class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                    />
                </div>
                <UiSelect v-model="filters.status" :label="t('common.status')" :placeholder="t('common.all')" :options="[{ value: 'active', label: t('common.active') }, { value: 'inactive', label: t('common.inactive') }]" />
                <UiButton v-if="filters.search || filters.status" variant="ghost" @click="list.resetFilters()"><RotateCcw class="size-4" />{{ t('common.reset') }}</UiButton>
            </div>

            <ul class="divide-y divide-line" :class="loading && 'opacity-50'" data-integration-list>
                <li v-for="account in rows" :key="account.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 text-sm" data-integration-row>
                    <div class="min-w-48 flex-1">
                        <p class="break-words font-medium text-fg" dir="auto">{{ account.name }}</p>
                        <p v-if="account.description" class="break-words text-xs text-muted" dir="auto">{{ account.description }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <UiBadge>{{ account.role }}</UiBadge>
                        <UiBadge :tone="account.status === 'active' ? 'success' : 'neutral'">{{ t(`common.${account.status}`) }}</UiBadge>
                    </div>
                    <p class="text-xs text-muted sm:w-44" data-integration-usage>
                        {{ t('integrations.tokenCount', { count: formatNumber(account.tokens_count) }, account.tokens_count) }}<br />
                        {{ account.last_used_at ? t('integrations.lastUsed', { when: formatRelative(account.last_used_at) }) : t('integrations.neverUsed') }}
                    </p>
                    <div class="ml-auto flex items-center gap-1">
                        <UiButton variant="secondary" data-integration-tokens-open @click="openTokens(account)"><KeyRound class="size-4" />{{ t('integrations.tokens.open') }}</UiButton>
                        <button type="button" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('common.edit')" data-integration-edit @click="openEdit(account)">
                            <Pencil class="size-4" />
                        </button>
                        <button v-if="within(account)" type="button" class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger" :aria-label="t('common.delete')" data-integration-delete @click="remove(account)">
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </li>
            </ul>
            <UiEmpty v-if="!loading && !rows.length" :title="filters.search || filters.status ? t('common.noResults') : t('integrations.empty')" :hint="filters.search || filters.status ? t('common.noResultsHint') : t('integrations.emptyHint')" data-integration-empty />
            <ListPagination :list="list" />
        </div>

        <IntegrationFormDialog v-model:open="formOpen" :account="editing" :roles="roles" @saved="saved" />
        <IntegrationTokensDialog v-model:open="tokensOpen" :account="tokensOf" :options="options" :can-make="tokensOf !== null && within(tokensOf)" @changed="list.load()" />
    </div>
</template>
