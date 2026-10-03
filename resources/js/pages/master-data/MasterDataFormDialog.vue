<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import type { MasterDataItem, MasterDataType } from '@/types';

const props = defineProps<{ definition: MasterDataType; item: MasterDataItem | null; parents: MasterDataItem[] }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [] }>();
const { t } = useI18n();
const ui = useUiStore();

const form = reactive({ name: '', name_local: '', code: '', parent_id: '', sort_order: 0 as number | string, is_active: true });
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const isEdit = computed(() => props.item !== null);

watch(open, (value) => {
    if (!value) {
        return;
    }
    errors.value = {};
    const item = props.item;
    Object.assign(form, {
        name: item?.name ?? '',
        name_local: item?.name_local ?? '',
        code: item?.code ?? '',
        parent_id: item?.parent?.id ?? '',
        sort_order: item?.sort_order ?? 0,
        is_active: item?.is_active ?? true,
    });
});

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    const payload: Record<string, unknown> = {
        name: form.name,
        name_local: form.name_local || null,
        sort_order: Number(form.sort_order) || 0,
        is_active: form.is_active,
    };
    if (props.definition.has_code) {
        payload.code = form.code || null;
    }
    if (props.definition.parent) {
        payload.parent_id = form.parent_id || null;
    }

    try {
        const base = `/master-data/${props.definition.type}`;
        if (isEdit.value) {
            await http.put(`${base}/${props.item!.id}`, payload);
        } else {
            await http.post(base, payload);
        }
        ui.notify(t('common.saved'));
        open.value = false;
        emit('saved');
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.errors;
        if (!Object.keys(apiError.errors).length) {
            ui.notify(apiError.message, 'error');
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <UiDialog v-model:open="open" :title="isEdit ? t('masterData.editTitle') : t('masterData.new')" :description="t(`masterData.types.${definition.type}`)">
        <form id="master-data-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <UiSelect
                v-if="definition.parent"
                v-model="form.parent_id"
                :label="t(`masterData.types.${definition.parent}`)"
                :placeholder="'—'"
                :options="parents.map((p) => ({ value: p.id, label: p.name }))"
                required
                :error="errors.parent_id"
                class="sm:col-span-2"
            />
            <UiInput v-model="form.name" :label="t('masterData.name')" required autofocus :error="errors.name" />
            <UiInput v-model="form.name_local" :label="t('masterData.nameLocal')" :error="errors.name_local" />
            <UiInput v-if="definition.has_code" v-model="form.code" :label="t('masterData.code')" :error="errors.code" />
            <UiInput v-model="form.sort_order" :label="t('masterData.sortOrder')" inputmode="numeric" :error="errors.sort_order" />
            <UiCheckbox v-model="form.is_active" :label="t('masterData.active')" class="sm:col-span-2" />
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="master-data-form" :loading="busy">{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
