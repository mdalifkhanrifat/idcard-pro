<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import CardholderImagePicker from '@/pages/cardholders/CardholderImagePicker.vue';
import { useMasterDataOptions } from '@/composables/useMasterDataOptions';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import type { Signatory } from '@/types';

const props = defineProps<{ signatory: Signatory | null }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [] }>();
const { t } = useI18n();
const ui = useUiStore();
const lookups = useMasterDataOptions();

const blank = () => ({ name: '', name_local: '', designation: '', designation_local: '', authority_type_id: '', effective_from: '', effective_to: '', is_active: true });
const form = reactive(blank());
const signature = ref<File | null>(null);
const removeSignature = ref(false);
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

watch(open, (value) => {
    if (!value) {
        return;
    }
    lookups.load(['authority_type']);
    errors.value = {};
    signature.value = null;
    removeSignature.value = false;
    const s = props.signatory;
    Object.assign(form, blank(), s ? {
        name: s.name, name_local: s.name_local ?? '', designation: s.designation, designation_local: s.designation_local ?? '',
        authority_type_id: s.authority_type?.id ?? '', effective_from: s.effective_from ?? '', effective_to: s.effective_to ?? '', is_active: s.is_active,
    } : {});
});

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    const payload = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, typeof value === 'string' ? value.trim() || null : value]));
    try {
        const { data } = props.signatory ? await http.put(`/signatories/${props.signatory.id}`, payload) : await http.post('/signatories', payload);
        const id = data.data.id;
        if (signature.value) {
            const body = new FormData();
            body.append('file', signature.value);
            try {
                await http.post(`/signatories/${id}/signature`, body);
            } catch (error) {
                const apiError = toApiError(error);
                ui.notify(t('signatories.uploadFailed', { message: apiError.errors.file?.[0] ?? apiError.message }), 'error');
            }
        } else if (removeSignature.value) {
            await http.delete(`/signatories/${id}/signature`);
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
    <UiDialog v-model:open="open" :title="signatory ? t('signatories.editTitle') : t('signatories.new')" size="lg">
        <form id="signatory-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <UiInput v-model="form.name" :label="t('signatories.name')" required :error="errors.name" />
            <UiInput v-model="form.name_local" :label="t('signatories.nameLocal')" :error="errors.name_local" />
            <UiInput v-model="form.designation" :label="t('signatories.designation')" required :error="errors.designation" />
            <UiInput v-model="form.designation_local" :label="t('signatories.designationLocal')" :error="errors.designation_local" />
            <UiSelect v-model="form.authority_type_id" :label="t('signatories.authorityType')" placeholder="—" :options="lookups.options('authority_type')" :error="errors.authority_type_id" class="sm:col-span-2" />
            <UiInput v-model="form.effective_from" type="date" :label="t('signatories.effectiveFrom')" :error="errors.effective_from" />
            <UiInput v-model="form.effective_to" type="date" :label="t('signatories.effectiveTo')" :error="errors.effective_to" />
            <UiCheckbox v-model="form.is_active" :label="t('signatories.active')" class="sm:col-span-2" />
            <div class="space-y-2 sm:col-span-2">
                <CardholderImagePicker
                    v-model="signature"
                    :label="t('signatories.signature')"
                    :current-url="removeSignature ? null : signatory?.signature_url"
                    :max-kb="2048"
                    aspect="wide"
                />
                <UiCheckbox v-if="signatory?.signature_url && !signature" v-model="removeSignature" :label="t('signatories.removeSignature')" />
            </div>
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="signatory-form" :loading="busy">{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
