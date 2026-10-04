<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useMasterDataOptions } from '@/composables/useMasterDataOptions';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import type { CardTemplate } from '@/types';

const props = defineProps<{ template: CardTemplate | null }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [] }>();
const { t } = useI18n();
const ui = useUiStore();
const lookups = useMasterDataOptions();

const CR80 = { width: 85.6, height: 54 };
const blank = () => ({
    name: '', code: '', card_type_id: '', audience: 'cardholder', orientation: 'horizontal', size_unit: 'mm',
    width: String(CR80.width) as string | number, height: String(CR80.height) as string | number,
    authority_type_id: '', validity_months: '24' as string | number, is_double_sided: true, is_active: true,
});
const form = reactive(blank());
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

const options = computed(() => ({
    audience: (['cardholder', 'family_member'] as const).map((value) => ({ value, label: t(`templates.audiences.${value}`) })),
    orientation: (['horizontal', 'vertical'] as const).map((value) => ({ value, label: t(`templates.orientations.${value}`) })),
}));

watch(open, (value) => {
    if (!value) {
        return;
    }
    lookups.load(['card_type', 'authority_type']);
    errors.value = {};
    const s = props.template;
    Object.assign(form, blank(), s ? {
        name: s.name, code: s.code, card_type_id: s.card_type?.id ?? '', audience: s.audience, orientation: s.orientation, size_unit: s.size_unit,
        width: s.width, height: s.height, authority_type_id: s.authority_type?.id ?? '', validity_months: s.validity_months ?? '',
        is_double_sided: s.is_double_sided, is_active: s.is_active,
    } : {});
});

/** Standard ID card size, turned for vertical cards. */
function useCr80(): void {
    form.size_unit = 'mm';
    [form.width, form.height] = form.orientation === 'vertical' ? [CR80.height, CR80.width] : [CR80.width, CR80.height];
}

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    const text = (value: string | number) => String(value ?? '').trim();
    const payload = {
        ...form,
        card_type_id: form.card_type_id || null,
        authority_type_id: form.authority_type_id || null,
        width: Number(text(form.width)),
        height: Number(text(form.height)),
        validity_months: text(form.validity_months) === '' ? null : Number(text(form.validity_months)),
    };
    try {
        await (props.template ? http.put(`/card-templates/${props.template.id}`, payload) : http.post('/card-templates', payload));
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
    <UiDialog v-model:open="open" :title="template ? t('templates.editTitle') : t('templates.new')" size="lg">
        <form id="template-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <UiInput v-model="form.name" :label="t('templates.name')" required :error="errors.name" />
            <UiInput v-model="form.code" :label="t('templates.code')" required :maxlength="20" :hint="t('templates.codeHint')" :error="errors.code" />
            <UiSelect v-model="form.card_type_id" :label="t('templates.cardType')" placeholder="—" :options="lookups.options('card_type')" :error="errors.card_type_id" />
            <UiSelect v-model="form.audience" :label="t('templates.audience')" :options="options.audience" :error="errors.audience" />
            <UiSelect v-model="form.orientation" :label="t('templates.orientation')" :options="options.orientation" :error="errors.orientation" />
            <UiSelect v-model="form.size_unit" :label="t('templates.unit')" :options="[{ value: 'mm', label: 'mm' }, { value: 'px', label: 'px' }]" :error="errors.size_unit" />
            <UiInput v-model="form.width" type="number" step="any" :label="t('templates.width')" required :error="errors.width" />
            <UiInput v-model="form.height" type="number" step="any" :label="t('templates.height')" required :error="errors.height" />
            <div class="sm:col-span-2">
                <button type="button" class="text-sm text-brand hover:underline" @click="useCr80">{{ t('templates.useCr80') }}</button>
            </div>
            <UiSelect
                v-model="form.authority_type_id"
                :label="t('templates.authorityType')"
                placeholder="—"
                :options="lookups.options('authority_type')"
                :error="errors.authority_type_id"
            />
            <UiInput v-model="form.validity_months" type="number" :label="t('templates.validity')" :hint="t('templates.validityHint')" :error="errors.validity_months" />
            <p class="-mt-2 text-xs text-muted sm:col-span-2">{{ t('templates.authorityHint') }}</p>
            <UiCheckbox v-model="form.is_double_sided" :label="t('templates.doubleSided')" />
            <UiCheckbox v-model="form.is_active" :label="t('templates.active')" />
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="template-form" :loading="busy">{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
