<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuTrigger } from 'reka-ui';
import { Layers, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import CustomFieldDialog from './CustomFieldDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { CustomField } from '@/types';

const { t, te } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const fields = ref<CustomField[]>([]);
const presets = ref<{ name: string; group: string | null; fields: number }[]>([]);
const loading = ref(true);
const dialogOpen = ref(false);
const editing = ref<CustomField | null>(null);
const canEdit = auth.can('settings.update');

async function load(): Promise<void> {
    loading.value = true;
    try {
        fields.value = (await http.get('/custom-fields', { params: { entity: 'cardholder' } })).data.data;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await load();
    if (canEdit) {
        presets.value = (await http.get('/custom-fields/presets')).data.data;
    }
});

function openForm(field: CustomField | null): void {
    editing.value = field;
    dialogOpen.value = true;
}

async function applyPreset(name: string): Promise<void> {
    try {
        const { data } = await http.post(`/custom-fields/presets/${name}`);
        ui.notify(t('customFields.presetApplied', { count: data.created }));
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}

async function remove(field: CustomField): Promise<void> {
    if (!(await confirm({ title: t('common.areYouSure'), message: t('customFields.deleteConfirm', { name: field.label }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/custom-fields/${field.id}`);
        ui.notify(t('common.deleted'));
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('customFields.title')" :subtitle="t('customFields.subtitle')">
            <DropdownMenuRoot v-if="canEdit && presets.length">
                <DropdownMenuTrigger as-child>
                    <UiButton variant="secondary"><Layers class="size-4" />{{ t('customFields.presets') }}</UiButton>
                </DropdownMenuTrigger>
                <DropdownMenuPortal>
                    <DropdownMenuContent :side-offset="6" align="end" class="z-50 min-w-64 rounded-xl border border-line bg-surface p-1 shadow-xl">
                        <DropdownMenuItem
                            v-for="preset in presets"
                            :key="preset.name"
                            class="cursor-pointer rounded-lg px-3 py-2 text-sm text-fg outline-none data-[highlighted]:bg-surface-2"
                            @select="applyPreset(preset.name)"
                        >
                            {{ te(`customFields.presetNames.${preset.name}`) ? t(`customFields.presetNames.${preset.name}`) : preset.group ?? preset.name }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenuPortal>
            </DropdownMenuRoot>
            <UiButton v-if="canEdit" @click="openForm(null)"><Plus class="size-4" />{{ t('customFields.new') }}</UiButton>
        </UiPageHeader>

        <div class="rounded-xl border border-line bg-surface">
            <div v-if="fields.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs font-medium tracking-wide whitespace-nowrap text-muted uppercase">
                        <tr class="border-b border-line">
                            <th class="px-5 py-3">{{ t('customFields.label') }}</th>
                            <th class="px-5 py-3">{{ t('customFields.key') }}</th>
                            <th class="px-5 py-3">{{ t('customFields.type') }}</th>
                            <th class="px-5 py-3">{{ t('customFields.group') }}</th>
                            <th class="px-5 py-3">{{ t('common.status') }}</th>
                            <th class="px-5 py-3 text-right">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody :class="loading && 'opacity-50'">
                        <tr v-for="field in fields" :key="field.id" class="border-b border-line last:border-0">
                            <td class="px-5 py-3">
                                <p class="font-medium text-fg">{{ field.label }}</p>
                                <p v-if="field.label_local" class="text-xs text-muted">{{ field.label_local }}</p>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs text-muted">{{ field.key }}</td>
                            <td class="px-5 py-3 text-muted">
                                {{ t(`customFields.types.${field.type}`) }}
                                <span v-if="field.options?.length" class="block truncate text-xs">{{ field.options.join(', ') }}</span>
                            </td>
                            <td class="px-5 py-3 text-muted">{{ field.group ?? '—' }}</td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <UiBadge :tone="field.is_active ? 'success' : 'neutral'">{{ field.is_active ? t('common.active') : t('common.inactive') }}</UiBadge>
                                    <UiBadge v-if="field.is_required" tone="warning">{{ t('customFields.required') }}</UiBadge>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <div v-if="canEdit" class="flex justify-end gap-1">
                                    <button class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('common.edit')" @click="openForm(field)"><Pencil class="size-4" /></button>
                                    <button class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger" :aria-label="t('common.delete')" @click="remove(field)"><Trash2 class="size-4" /></button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <UiEmpty v-else-if="!loading" :title="t('customFields.empty')" />
        </div>

        <CustomFieldDialog v-model:open="dialogOpen" :field="editing" @saved="load" />
    </div>
</template>
