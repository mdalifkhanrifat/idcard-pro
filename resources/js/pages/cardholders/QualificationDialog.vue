<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import type { Qualification, QualificationKind } from '@/types';

const props = defineProps<{ cardholderId: string; kind: QualificationKind; record: Qualification | null }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [] }>();
const { t } = useI18n();
const ui = useUiStore();

/** Fields shown per kind, matching the legacy education and course forms. */
const FIELDS: Record<QualificationKind, string[]> = {
    education: ['title', 'institution', 'year', 'subject', 'degree', 'result'],
    course: ['title', 'institution', 'year', 'result', 'certificate_no'],
};
const blank = () => ({ title: '', institution: '', year: '', subject: '', degree: '', result: '', certificate_no: '' });
const form = reactive<Record<string, string>>(blank());
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const fields = computed(() => FIELDS[props.kind]);
const title = computed(() => (props.kind === 'education'
    ? t(props.record ? 'cardholders.qualifications.editEducation' : 'cardholders.qualifications.addEducation')
    : t(props.record ? 'cardholders.qualifications.editCourse' : 'cardholders.qualifications.addCourse')));

watch(open, (value) => {
    if (!value) {
        return;
    }
    errors.value = {};
    Object.assign(form, blank());
    if (props.record) {
        for (const key of Object.keys(form)) {
            const stored = props.record[key as keyof Qualification];
            form[key] = stored === null || stored === undefined ? '' : String(stored);
        }
    }
});

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    const payload: Record<string, string | number | null> = { kind: props.kind };
    for (const key of fields.value) {
        const value = String(form[key] ?? '').trim();
        payload[key] = value === '' ? null : key === 'year' ? Number(value) : value;
    }
    try {
        const base = `/cardholders/${props.cardholderId}/qualifications`;
        await (props.record ? http.put(`${base}/${props.record.id}`, payload) : http.post(base, payload));
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
    <UiDialog v-model:open="open" :title="title">
        <form id="qualification-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <UiInput
                v-for="field in fields"
                :key="field"
                v-model="form[field]"
                :type="field === 'year' ? 'number' : 'text'"
                :inputmode="field === 'year' ? 'numeric' : undefined"
                :label="t(`cardholders.qualifications.fields.${kind}.${field}`)"
                :required="field === 'title'"
                :error="errors[field]"
                :class="field === 'title' && 'sm:col-span-2'"
            />
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="qualification-form" :loading="busy">{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
