<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import type { CustomField, CustomFieldType } from '@/types';

const props = defineProps<{ field: CustomField | null }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [] }>();
const { t } = useI18n();
const ui = useUiStore();

const TYPES: CustomFieldType[] = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];
const blank = () => ({ key: '', label: '', label_local: '', group: '', type: 'text' as CustomFieldType, options: '', is_required: false, is_active: true, sort_order: '' as string | number });
const form = reactive(blank());
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const keyTouched = ref(false);
const isEdit = computed(() => props.field !== null);
const typeOptions = computed(() => TYPES.map((value) => ({ value, label: t(`customFields.types.${value}`) })));

watch(open, (value) => {
    if (!value) {
        return;
    }
    errors.value = {};
    keyTouched.value = false;
    const f = props.field;
    Object.assign(form, blank(), f ? {
        key: f.key, label: f.label, label_local: f.label_local ?? '', group: f.group ?? '', type: f.type,
        options: (f.options ?? []).join('\n'), is_required: f.is_required, is_active: f.is_active, sort_order: f.sort_order,
    } : {});
});

// Suggest a key from the label until the user edits the key.
watch(() => form.label, (label) => {
    if (!isEdit.value && !keyTouched.value) {
        form.key = label.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'f_$1').slice(0, 40);
    }
});

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    const payload: Record<string, unknown> = {
        label: form.label.trim(),
        label_local: form.label_local.trim() || null,
        group: form.group.trim() || null,
        is_required: form.is_required,
        is_active: form.is_active,
    };
    if (String(form.sort_order).trim() !== '') {
        payload.sort_order = Number(form.sort_order);
    }
    if (form.type === 'select') {
        payload.options = form.options.split('\n').map((line) => line.trim()).filter(Boolean);
    }
    if (!isEdit.value) {
        Object.assign(payload, { entity: 'cardholder', key: form.key, type: form.type });
    }
    try {
        await (isEdit.value ? http.put(`/custom-fields/${props.field!.id}`, payload) : http.post('/custom-fields', payload));
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
    <UiDialog v-model:open="open" :title="isEdit ? t('customFields.editTitle') : t('customFields.new')" size="lg">
        <form id="custom-field-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <UiInput v-model="form.label" :label="t('customFields.label')" required :error="errors.label" />
            <UiInput v-model="form.label_local" :label="t('customFields.labelLocal')" :error="errors.label_local" />
            <div @input="keyTouched = true">
                <UiInput v-model="form.key" :label="t('customFields.key')" :disabled="isEdit" required :hint="t('customFields.keyHint')" :error="errors.key" />
            </div>
            <UiSelect v-if="!isEdit" v-model="form.type" :label="t('customFields.type')" required :options="typeOptions" :error="errors.type" />
            <UiInput v-else :model-value="t(`customFields.types.${form.type}`)" :label="t('customFields.type')" disabled :hint="t('customFields.typeFixed')" />
            <UiTextarea
                v-if="form.type === 'select'"
                v-model="form.options"
                :rows="4"
                :label="t('customFields.options')"
                required
                :hint="t('customFields.optionsHint')"
                :error="errors.options ?? errors['options.0']"
                class="sm:col-span-2"
            />
            <UiInput v-model="form.group" :label="t('customFields.group')" :hint="t('customFields.groupHint')" :error="errors.group" />
            <UiInput v-model="form.sort_order" type="number" :label="t('customFields.order')" :error="errors.sort_order" />
            <div class="flex gap-6 sm:col-span-2">
                <UiCheckbox v-model="form.is_required" :label="t('customFields.required')" />
                <UiCheckbox v-model="form.is_active" :label="t('customFields.active')" />
            </div>
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="custom-field-form" :loading="busy">{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
