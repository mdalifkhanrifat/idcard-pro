<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { useI18n } from 'vue-i18n';
import { Copy, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import CardTemplateDialog from './CardTemplateDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useMasterDataOptions } from '@/composables/useMasterDataOptions';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { CardTemplate } from '@/types';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();
const lookups = useMasterDataOptions();

const templates = ref<CardTemplate[]>([]);
const loading = ref(true);
const filters = reactive({ search: '', card_type: '', audience: '', is_active: '' });
const dialogOpen = ref(false);
const editing = ref<CardTemplate | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '').map(([k, v]) => [`filter[${k}]`, v]));
        templates.value = (await http.get('/card-templates', { params })).data.data;
    } finally {
        loading.value = false;
    }
}

watchDebounced(() => ({ ...filters }), load, { debounce: 300, deep: true });
onMounted(() => {
    load();
    lookups.load(['card_type']);
});

/** Card outline scaled to fit a 120px box, keeping the real proportions. */
function shape(template: CardTemplate): { width: string; height: string } {
    const scale = 120 / Math.max(template.width, template.height);

    return { width: `${template.width * scale}px`, height: `${template.height * scale}px` };
}

function openForm(template: CardTemplate | null): void {
    editing.value = template;
    dialogOpen.value = true;
}

async function duplicate(template: CardTemplate): Promise<void> {
    try {
        await http.post(`/card-templates/${template.id}/duplicate`, { name: t('templates.duplicateName', { name: template.name }) });
        ui.notify(t('templates.duplicated'));
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}

async function remove(template: CardTemplate): Promise<void> {
    if (!(await confirm({ title: t('templates.deleteConfirm', { name: template.name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/card-templates/${template.id}`);
        ui.notify(t('common.deleted'));
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('templates.title')" :subtitle="t('templates.subtitle')">
            <UiButton v-if="auth.can('templates.create')" @click="openForm(null)"><Plus class="size-4" />{{ t('templates.new') }}</UiButton>
        </UiPageHeader>

        <div class="mb-5 flex flex-col gap-3 rounded-xl border border-line bg-surface p-4 md:flex-row">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                <input
                    v-model="filters.search"
                    type="search"
                    :placeholder="t('templates.searchPlaceholder')"
                    class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                />
            </div>
            <UiSelect v-model="filters.card_type" class="md:w-48" :placeholder="`${t('templates.cardType')}: ${t('common.all')}`" :options="lookups.options('card_type')" />
            <UiSelect
                v-model="filters.audience"
                class="md:w-48"
                :placeholder="`${t('templates.audience')}: ${t('common.all')}`"
                :options="[{ value: 'cardholder', label: t('templates.audiences.cardholder') }, { value: 'family_member', label: t('templates.audiences.family_member') }]"
            />
            <UiSelect
                v-model="filters.is_active"
                class="md:w-40"
                :placeholder="`${t('common.status')}: ${t('common.all')}`"
                :options="[{ value: '1', label: t('common.active') }, { value: '0', label: t('common.inactive') }]"
            />
        </div>

        <div v-if="templates.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" :class="loading && 'opacity-50'">
            <article v-for="item in templates" :key="item.id" class="flex gap-4 rounded-xl border border-line bg-surface p-4">
                <div class="flex size-32 shrink-0 items-center justify-center rounded-lg bg-surface-2">
                    <div class="flex items-center justify-center rounded-md border-2 border-brand/40 bg-surface font-mono text-xs text-brand shadow-sm" :style="shape(item)">
                        {{ item.code }}
                    </div>
                </div>
                <div class="flex min-w-0 flex-1 flex-col">
                    <p class="truncate font-medium text-fg">{{ item.name }}</p>
                    <p class="truncate text-xs text-muted">{{ item.card_type?.name ?? '—' }} · {{ t(`templates.audiences.${item.audience}`) }}</p>
                    <p class="mt-1 text-xs text-muted">
                        {{ item.width }} × {{ item.height }} {{ item.size_unit }} · {{ t(`templates.orientations.${item.orientation}`) }} ·
                        {{ item.validity_months ? t('templates.months', { n: item.validity_months }) : t('templates.noExpiry') }}
                    </p>
                    <div class="mt-2 flex flex-wrap gap-1">
                        <UiBadge :tone="item.is_active ? 'success' : 'neutral'">{{ item.is_active ? t('common.active') : t('common.inactive') }}</UiBadge>
                        <UiBadge>{{ t('templates.version', { n: item.version }) }}</UiBadge>
                        <UiBadge v-if="item.authority_type" tone="brand">{{ item.authority_type.name }}</UiBadge>
                    </div>
                    <div class="mt-auto flex justify-end gap-1 pt-2">
                        <button v-if="auth.can('templates.create')" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :title="t('templates.duplicate')" @click="duplicate(item)"><Copy class="size-4" /></button>
                        <button v-if="auth.can('templates.update')" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :title="t('common.edit')" @click="openForm(item)"><Pencil class="size-4" /></button>
                        <button v-if="auth.can('templates.delete')" class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger" :title="t('common.delete')" @click="remove(item)"><Trash2 class="size-4" /></button>
                    </div>
                </div>
            </article>
        </div>
        <div v-else-if="!loading" class="rounded-xl border border-line bg-surface"><UiEmpty :title="t('templates.empty')" /></div>

        <CardTemplateDialog v-model:open="dialogOpen" :template="editing" @saved="load" />
    </div>
</template>
