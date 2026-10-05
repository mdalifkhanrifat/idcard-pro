<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import CardholderImagePicker from './CardholderImagePicker.vue';
import { FILE_LIMITS, uploadHolderImages } from './cardholder';
import { useMasterDataOptions } from '@/composables/useMasterDataOptions';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import type { FamilyMember } from '@/types';

const props = defineProps<{ cardholderId: string; member: FamilyMember | null }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [] }>();
const { t } = useI18n();
const ui = useUiStore();
const lookups = useMasterDataOptions();

const RELATIONS = ['father', 'mother', 'father_in_law', 'mother_in_law', 'spouse', 'son', 'daughter', 'sibling', 'other'] as const;
const blank = () => ({
    relation: '', name: '', name_local: '', gender: '', date_of_birth: '', phone: '', blood_group_id: '',
    national_id: '', passport_no: '', identification_mark: '', card_no: '',
});
const form = reactive(blank());
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const photo = ref<File | null>(null);
const signature = ref<File | null>(null);

const relationOptions = computed(() => RELATIONS.map((value) => ({ value, label: t(`cardholders.family.relations.${value}`) })));
const genderOptions = computed(() => (['male', 'female', 'other'] as const).map((value) => ({ value, label: t(`cardholders.gender.${value}`) })));

watch(open, (value) => {
    if (!value) {
        return;
    }
    lookups.load(['blood_group']);
    errors.value = {};
    photo.value = null;
    signature.value = null;
    const m = props.member;
    Object.assign(form, blank(), m ? {
        relation: m.relation, name: m.name, name_local: m.name_local ?? '', gender: m.gender ?? '', date_of_birth: m.date_of_birth ?? '', phone: m.phone ?? '',
        blood_group_id: m.blood_group?.id ?? '', national_id: m.national_id ?? '', passport_no: m.passport_no ?? '',
        identification_mark: m.identification_mark ?? '', card_no: m.card_no ?? '',
    } : {});
});

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    const payload = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()]));
    try {
        const base = `/cardholders/${props.cardholderId}/family-members`;
        const { data } = await (props.member ? http.put(`${base}/${props.member.id}`, payload) : http.post(base, payload));
        // The member's own photo and signature are uploaded once the member is saved
        await uploadHolderImages(props.cardholderId, { photo: photo.value, signature: signature.value }, (collection, message) =>
            ui.notify(t('cardholders.files.uploadFailed', { name: t(`cardholders.files.${collection}`), message }), 'error'), data.data.id);
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
    <UiDialog v-model:open="open" :title="member ? t('cardholders.family.editTitle') : t('cardholders.family.add')" size="lg">
        <form id="family-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <UiSelect v-model="form.relation" :label="t('cardholders.family.relation')" placeholder="—" required :options="relationOptions" :error="errors.relation" />
            <UiInput v-model="form.name" :label="t('cardholders.family.name')" required :error="errors.name" />
            <UiInput v-model="form.name_local" :label="t('cardholders.family.nameLocal')" :error="errors.name_local" />
            <UiSelect v-model="form.gender" :label="t('cardholders.fields.gender')" placeholder="—" :options="genderOptions" :error="errors.gender" />
            <UiInput v-model="form.date_of_birth" type="date" :label="t('cardholders.fields.date_of_birth')" :error="errors.date_of_birth" />
            <UiInput v-model="form.phone" inputmode="tel" :label="t('cardholders.family.phone')" :error="errors.phone" />
            <UiSelect v-model="form.blood_group_id" :label="t('cardholders.fields.blood_group_id')" placeholder="—" :options="lookups.options('blood_group')" :error="errors.blood_group_id" />
            <UiInput v-model="form.national_id" :label="t('cardholders.fields.national_id')" :error="errors.national_id" />
            <UiInput v-model="form.passport_no" :label="t('cardholders.fields.passport_no')" :error="errors.passport_no" />
            <UiInput v-model="form.card_no" :label="t('cardholders.family.cardNo')" :error="errors.card_no" />
            <UiInput v-model="form.identification_mark" :label="t('cardholders.fields.identification_mark')" :error="errors.identification_mark" class="sm:col-span-2" />
            <div class="grid gap-4 sm:col-span-2 sm:grid-cols-2">
                <CardholderImagePicker v-model="photo" :label="t('cardholders.files.photo')" :current-url="member?.photo?.url" :max-kb="FILE_LIMITS.photo" />
                <CardholderImagePicker v-model="signature" :label="t('cardholders.files.signature')" :current-url="member?.signature?.url" :max-kb="FILE_LIMITS.signature" aspect="wide" />
                <p class="text-xs text-muted sm:col-span-2">{{ t('cardholders.family.imagesHint') }}</p>
            </div>
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="family-form" :loading="busy">{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
