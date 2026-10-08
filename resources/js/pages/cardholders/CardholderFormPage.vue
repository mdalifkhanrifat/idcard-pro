<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Loader2 } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import CardholderImagePicker from './CardholderImagePicker.vue';
import CustomFieldInputs from './CustomFieldInputs.vue';
import { useCustomFields } from '@/composables/useCustomFields';
import { useMasterDataOptions } from '@/composables/useMasterDataOptions';
import { http, toApiError } from '@/lib/http';
import { blankForm, FILE_LIMITS, formFromCardholder, LOOKUP_FIELDS, toPayload, uploadHolderImages } from './cardholder';
import { useUiStore } from '@/stores/ui';
import type { Cardholder } from '@/types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const ui = useUiStore();
const lookups = useMasterDataOptions();
const customFields = useCustomFields();
const custom = ref<Record<string, string | boolean>>({});

const editingId = computed(() => (route.params.id as string | undefined) ?? null);
const form = reactive(blankForm());
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const loading = ref(false);
const existing = ref<Cardholder | null>(null);
const photo = ref<File | null>(null);
const signature = ref<File | null>(null);
const sameAddress = ref(false);

const tabs = [
    { key: 'identity', fields: ['full_name', 'full_name_local', 'date_of_birth', 'gender', 'marital_status', 'nationality', 'national_id', 'passport_no', 'blood_group_id', 'religion_id'] },
    { key: 'contact', fields: ['email', 'personal_phone', 'emergency_phone', 'present_address', 'permanent_address', 'region_id', 'sub_region_id', 'postal_code'] },
    { key: 'physical', fields: ['height', 'weight', 'eye_color', 'complexion', 'identification_mark'] },
    {
        key: 'office',
        fields: ['employee_no', 'secondary_no', 'employment_type', 'department_id', 'unit_id', 'rank_id', 'designation_id', 'branch_id', 'trade_id', 'appointment_id',
            'joining_date', 'retirement_date', 'retired_id_no', 'posting', 'previous_office', 'status'],
    },
    { key: 'external', fields: ['company_name', 'company_address', 'company_phone'] },
    { key: 'additional', fields: ['custom_fields'] },
    { key: 'images', fields: [] as string[] },
] as const;
const activeTab = ref<(typeof tabs)[number]['key']>('identity');
// Custom field errors arrive as "custom_fields.<key>".
const tabHasErrors = (fields: readonly string[]) => Object.keys(errors.value).some((key) => fields.some((field) => key === field || key.startsWith(`${field}.`)));
const visibleTabs = computed(() => tabs.filter((tab) => tab.key !== 'additional' || customFields.fields.value.length));

const options = computed(() => ({
    gender: (['male', 'female', 'other'] as const).map((value) => ({ value, label: t(`cardholders.gender.${value}`) })),
    marital: (['single', 'married', 'divorced', 'widowed'] as const).map((value) => ({ value, label: t(`cardholders.maritalStatus.${value}`) })),
    employment: (['staff', 'contractor', 'visitor', 'student', 'other'] as const).map((value) => ({ value, label: t(`cardholders.employmentType.${value}`) })),
    status: (['active', 'inactive', 'retired'] as const).map((value) => ({ value, label: t(`cardholders.status.${value}`) })),
}));

// Child lists depend on their parent: clear the child when the parent changes.
watch(() => form.department_id, (value, previous) => previous !== value && !loading.value && (form.unit_id = ''));
watch(() => form.region_id, (value, previous) => previous !== value && !loading.value && (form.sub_region_id = ''));
watch([sameAddress, () => form.present_address], () => sameAddress.value && (form.permanent_address = form.present_address));

onMounted(async () => {
    lookups.load(Object.values(LOOKUP_FIELDS));
    await customFields.load();
    custom.value = Object.fromEntries(customFields.fields.value.map((field) => [field.key, field.type === 'checkbox' ? false : '']));
    if (!editingId.value) {
        return;
    }
    loading.value = true;
    try {
        existing.value = (await http.get(`/cardholders/${editingId.value}`)).data.data;
        Object.assign(form, formFromCardholder(existing.value!));
        for (const field of customFields.fields.value) {
            const stored = existing.value!.custom_fields?.[field.key];
            custom.value[field.key] = field.type === 'checkbox' ? stored === true : stored === undefined || stored === null ? '' : String(stored);
        }
        sameAddress.value = !!form.present_address && form.present_address === form.permanent_address;
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
        router.replace('/cardholders');
    } finally {
        // Let the parent watchers see the loaded values before they may clear children again.
        setTimeout(() => (loading.value = false));
    }
});

function uploadImages(id: string): Promise<void> {
    return uploadHolderImages(id, { photo: photo.value, signature: signature.value }, (collection, message) =>
        ui.notify(t('cardholders.files.uploadFailed', { name: t(`cardholders.files.${collection}`), message }), 'error'));
}

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        const payload = {
            ...toPayload(form),
            custom_fields: Object.fromEntries(Object.entries(custom.value).map(([key, value]) => [key, typeof value === 'boolean' ? value : String(value).trim() || null])),
        };
        const { data } = editingId.value ? await http.put(`/cardholders/${editingId.value}`, payload) : await http.post('/cardholders', payload);
        await uploadImages(data.data.id);
        ui.notify(editingId.value ? t('common.saved') : t('cardholders.created'));
        router.push(`/cardholders/${data.data.id}`);
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.errors;
        const firstTab = tabs.find((tab) => tabHasErrors(tab.fields));
        if (firstTab) {
            activeTab.value = firstTab.key;
        } else {
            ui.notify(apiError.message, 'error');
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <RouterLink :to="existing ? `/cardholders/${existing.id}` : '/cardholders'" class="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-fg">
            <ArrowLeft class="size-4" />{{ existing ? existing.full_name : t('cardholders.back') }}
        </RouterLink>
        <UiPageHeader :title="editingId ? t('cardholders.editTitle') : t('cardholders.new')" :subtitle="existing?.employee_no ?? undefined">
            <UiButton variant="secondary" @click="router.back()">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="cardholder-form" :loading="busy" :disabled="loading">{{ t('common.save') }}</UiButton>
        </UiPageHeader>

        <div v-if="loading" class="flex justify-center py-20 text-muted"><Loader2 class="size-6 animate-spin" /></div>

        <form v-else id="cardholder-form" class="rounded-xl border border-line bg-surface" novalidate @submit.prevent="submit">
            <!-- Tabs -->
            <div class="flex gap-1 overflow-x-auto border-b border-line px-3" role="tablist">
                <button
                    v-for="tab in visibleTabs"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab.key"
                    class="relative -mb-px flex items-center gap-2 border-b-2 px-3 py-3 text-sm whitespace-nowrap transition-colors"
                    :class="activeTab === tab.key ? 'border-brand-ink font-medium text-brand-ink' : 'border-transparent text-muted hover:text-fg'"
                    @click="activeTab = tab.key"
                >
                    {{ t(`cardholders.sections.${tab.key}`) }}
                    <span v-if="tabHasErrors(tab.fields)" class="size-2 rounded-full bg-danger" :title="t('cardholders.tabErrors')" />
                </button>
            </div>

            <div class="p-5 sm:p-6">
                <div v-show="activeTab === 'identity'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <UiInput v-model="form.full_name" :label="t('cardholders.fields.full_name')" required :error="errors.full_name" class="lg:col-span-2" />
                    <UiInput v-model="form.full_name_local" :label="t('cardholders.fields.full_name_local')" :error="errors.full_name_local" />
                    <UiInput v-model="form.date_of_birth" type="date" :label="t('cardholders.fields.date_of_birth')" :error="errors.date_of_birth" />
                    <UiSelect v-model="form.gender" :label="t('cardholders.fields.gender')" placeholder="—" :options="options.gender" :error="errors.gender" />
                    <UiSelect v-model="form.marital_status" :label="t('cardholders.fields.marital_status')" placeholder="—" :options="options.marital" :error="errors.marital_status" />
                    <UiInput v-model="form.nationality" :label="t('cardholders.fields.nationality')" :error="errors.nationality" />
                    <UiInput v-model="form.national_id" :label="t('cardholders.fields.national_id')" :error="errors.national_id" />
                    <UiInput v-model="form.passport_no" :label="t('cardholders.fields.passport_no')" :error="errors.passport_no" />
                    <UiSelect v-model="form.blood_group_id" :label="t('cardholders.fields.blood_group_id')" placeholder="—" :options="lookups.options('blood_group')" :error="errors.blood_group_id" />
                    <UiSelect v-model="form.religion_id" :label="t('cardholders.fields.religion_id')" placeholder="—" :options="lookups.options('religion')" :error="errors.religion_id" />
                </div>

                <div v-show="activeTab === 'contact'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <UiInput v-model="form.email" type="email" :label="t('cardholders.fields.email')" :error="errors.email" />
                    <UiInput v-model="form.personal_phone" inputmode="tel" :label="t('cardholders.fields.personal_phone')" :error="errors.personal_phone" />
                    <UiInput v-model="form.emergency_phone" inputmode="tel" :label="t('cardholders.fields.emergency_phone')" :error="errors.emergency_phone" />
                    <UiTextarea v-model="form.present_address" :label="t('cardholders.fields.present_address')" :error="errors.present_address" class="sm:col-span-2 lg:col-span-3" />
                    <div class="space-y-2 sm:col-span-2 lg:col-span-3">
                        <UiTextarea v-if="!sameAddress" v-model="form.permanent_address" :label="t('cardholders.fields.permanent_address')" :error="errors.permanent_address" />
                        <UiCheckbox v-model="sameAddress" :label="t('cardholders.sameAsPresent')" />
                    </div>
                    <UiSelect v-model="form.region_id" :label="t('cardholders.fields.region_id')" placeholder="—" :options="lookups.options('region')" :error="errors.region_id" />
                    <UiSelect
                        v-model="form.sub_region_id"
                        :label="t('cardholders.fields.sub_region_id')"
                        :placeholder="form.region_id ? '—' : t('cardholders.selectRegionFirst')"
                        :options="form.region_id ? lookups.options('sub_region', form.region_id) : []"
                        :error="errors.sub_region_id"
                    />
                    <UiInput v-model="form.postal_code" :label="t('cardholders.fields.postal_code')" :error="errors.postal_code" />
                </div>

                <div v-show="activeTab === 'physical'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <UiInput v-model="form.height" :label="t('cardholders.fields.height')" :error="errors.height" />
                    <UiInput v-model="form.weight" :label="t('cardholders.fields.weight')" :error="errors.weight" />
                    <UiInput v-model="form.eye_color" :label="t('cardholders.fields.eye_color')" :error="errors.eye_color" />
                    <UiInput v-model="form.complexion" :label="t('cardholders.fields.complexion')" :error="errors.complexion" />
                    <UiInput v-model="form.identification_mark" :label="t('cardholders.fields.identification_mark')" :error="errors.identification_mark" class="sm:col-span-2 lg:col-span-4" />
                </div>

                <div v-show="activeTab === 'office'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <UiInput v-model="form.employee_no" :label="t('cardholders.fields.employee_no')" :error="errors.employee_no" />
                    <UiInput v-model="form.secondary_no" :label="t('cardholders.fields.secondary_no')" :error="errors.secondary_no" />
                    <UiSelect v-model="form.employment_type" :label="t('cardholders.fields.employment_type')" :options="options.employment" :error="errors.employment_type" />
                    <UiSelect v-model="form.department_id" :label="t('cardholders.fields.department_id')" placeholder="—" :options="lookups.options('department')" :error="errors.department_id" />
                    <UiSelect
                        v-model="form.unit_id"
                        :label="t('cardholders.fields.unit_id')"
                        :placeholder="form.department_id ? '—' : t('cardholders.selectDepartmentFirst')"
                        :options="form.department_id ? lookups.options('unit', form.department_id) : []"
                        :error="errors.unit_id"
                    />
                    <UiSelect v-model="form.rank_id" :label="t('cardholders.fields.rank_id')" placeholder="—" :options="lookups.options('rank')" :error="errors.rank_id" />
                    <UiSelect v-model="form.designation_id" :label="t('cardholders.fields.designation_id')" placeholder="—" :options="lookups.options('designation')" :error="errors.designation_id" />
                    <UiSelect v-model="form.branch_id" :label="t('cardholders.fields.branch_id')" placeholder="—" :options="lookups.options('branch')" :error="errors.branch_id" />
                    <UiSelect v-model="form.trade_id" :label="t('cardholders.fields.trade_id')" placeholder="—" :options="lookups.options('trade')" :error="errors.trade_id" />
                    <UiSelect v-model="form.appointment_id" :label="t('cardholders.fields.appointment_id')" placeholder="—" :options="lookups.options('appointment')" :error="errors.appointment_id" />
                    <UiInput v-model="form.joining_date" type="date" :label="t('cardholders.fields.joining_date')" :error="errors.joining_date" />
                    <UiInput v-model="form.retirement_date" type="date" :label="t('cardholders.fields.retirement_date')" :error="errors.retirement_date" />
                    <UiInput v-model="form.retired_id_no" :label="t('cardholders.fields.retired_id_no')" :error="errors.retired_id_no" />
                    <UiInput v-model="form.posting" :label="t('cardholders.fields.posting')" :error="errors.posting" />
                    <UiInput v-model="form.previous_office" :label="t('cardholders.fields.previous_office')" :error="errors.previous_office" />
                    <UiSelect v-model="form.status" :label="t('cardholders.fields.status')" :options="options.status" :error="errors.status" />
                </div>

                <div v-show="activeTab === 'external'" class="grid gap-4 sm:grid-cols-2">
                    <UiInput v-model="form.company_name" :label="t('cardholders.fields.company_name')" :error="errors.company_name" />
                    <UiInput v-model="form.company_phone" inputmode="tel" :label="t('cardholders.fields.company_phone')" :error="errors.company_phone" />
                    <UiTextarea v-model="form.company_address" :rows="2" :label="t('cardholders.fields.company_address')" :error="errors.company_address" class="sm:col-span-2" />
                </div>

                <CustomFieldInputs v-show="activeTab === 'additional'" v-model="custom" :groups="customFields.groups.value" :label="customFields.label" :errors="errors" />

                <div v-show="activeTab === 'images'" class="grid gap-8 sm:grid-cols-2">
                    <CardholderImagePicker v-model="photo" :label="t('cardholders.files.photo')" :current-url="existing?.photo?.url" :max-kb="FILE_LIMITS.photo" />
                    <CardholderImagePicker v-model="signature" :label="t('cardholders.files.signature')" :current-url="existing?.signature?.url" :max-kb="FILE_LIMITS.signature" aspect="wide" />
                </div>
            </div>
        </form>
    </div>
</template>
