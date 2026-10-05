<script setup lang="ts">
import { computed, reactive, ref, useTemplateRef, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { useI18n } from 'vue-i18n';
import { FileUp, Loader2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import CardRender from './CardRender.vue';
import { useCardReasons } from './cards';
import { http, toApiError } from '@/lib/http';
import { formatBytes } from '@/lib/format';
import { useUiStore } from '@/stores/ui';
import type { CardLayout, CardTemplate, CardValues, Cardholder, FamilyMember } from '@/types';

/**
 * Issue a card (legacy card form + "Reason For Print"): for the cardholder or a family member, from an
 * active template of the matching kind, with a live preview of the real card and the number it will get.
 */
const props = defineProps<{ cardholder: Pick<Cardholder, 'id' | 'full_name'>; /** Preselect a holder: '' = the cardholder. */ familyMemberId?: string }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ issued: [cardId: string] }>();
const { t, te } = useI18n();
const ui = useUiStore();
const cardReasons = useCardReasons();
/** Reasons added on the server without a translation show their key. */
const reasonLabel = (key: string) => (te(`cards.reasons.${key}`) ? t(`cards.reasons.${key}`) : key);

const templates = ref<CardTemplate[]>([]);
const members = ref<FamilyMember[]>([]);
const form = reactive({ holder: '', template: '', reason: 'new', notes: '', issue_date: '', expiry_date: '', rf_card_no: '' });
const document = ref<File | null>(null);
const documentInput = useTemplateRef<HTMLInputElement>('documentInput');
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const nextNumber = ref<{ number: string; kept: boolean } | null>(null);
const preview = ref<{ layout: CardLayout; values: CardValues } | null>(null);
const previewLoading = ref(false);

const today = () => new Date().toISOString().slice(0, 10);

watch(open, async (value) => {
    if (!value) {
        return;
    }
    Object.assign(form, { holder: props.familyMemberId ?? '', template: '', reason: 'new', notes: '', issue_date: today(), expiry_date: '', rf_card_no: '' });
    document.value = null;
    errors.value = {};
    preview.value = null;
    nextNumber.value = null;
    const [templateList, memberList] = await Promise.all([
        http.get('/card-templates', { params: { 'filter[is_active]': 1, include: 'elements', per_page: 100 } }),
        http.get(`/cardholders/${props.cardholder.id}/family-members`),
    ]);
    templates.value = templateList.data.data;
    members.value = memberList.data.data;
    pickDefaultTemplate();
});

/** Family templates for family members, cardholder templates for the cardholder. */
const forFamily = computed(() => form.holder !== '');
const usableTemplates = computed(() => templates.value.filter((template) => template.audience === (forFamily.value ? 'family_member' : 'cardholder')));
const template = computed(() => templates.value.find((item) => item.id === form.template) ?? null);
const needs = computed(() => cardReasons.needs(form.reason));

function pickDefaultTemplate(): void {
    if (!usableTemplates.value.some((item) => item.id === form.template)) {
        form.template = usableTemplates.value.length === 1 ? usableTemplates.value[0].id : '';
    }
}

watch(() => form.holder, pickDefaultTemplate);

/** Expiry follows the template's validity (legacy: fixed 2 years on import) until the user sets it. */
let expiryTouched = false;
watch([() => form.template, () => form.issue_date], () => {
    if (expiryTouched || !template.value) {
        return;
    }
    const months = template.value.validity_months;
    if (!months || !form.issue_date) {
        form.expiry_date = '';

        return;
    }
    const date = new Date(`${form.issue_date}T00:00:00`);
    date.setMonth(date.getMonth() + months);
    date.setDate(date.getDate() - 1);
    form.expiry_date = date.toISOString().slice(0, 10);
});
watch(open, () => (expiryTouched = false));

/** Preview with the holder's current data, plus the number and dates the card will get. */
watchDebounced(
    () => [form.template, form.holder, form.issue_date, form.expiry_date, open.value],
    async () => {
        if (!open.value || !template.value) {
            preview.value = null;
            nextNumber.value = null;

            return;
        }
        previewLoading.value = true;
        try {
            const params = { cardholder: props.cardholder.id, family_member: form.holder || undefined };
            const [values, number] = await Promise.all([
                http.get(`/card-templates/${template.value.id}/preview`, { params }),
                http.get(`/cardholders/${props.cardholder.id}/cards/next-number`, { params: { template: template.value.id, family_member: form.holder || undefined } }),
            ]);
            nextNumber.value = number.data.data;
            const display = (iso: string) => (iso ? iso.split('-').reverse().join('/') : '');
            preview.value = {
                layout: {
                    size_mm: { width: template.value.width * (template.value.size_unit === 'px' ? 25.4 / 96 : 1), height: template.value.height * (template.value.size_unit === 'px' ? 25.4 / 96 : 1) },
                    orientation: template.value.orientation,
                    is_double_sided: template.value.is_double_sided,
                    elements: template.value.elements ?? [],
                },
                values: {
                    ...values.data.data,
                    fields: { ...values.data.data.fields, 'card.number': nextNumber.value?.number ?? '', 'card.issue_date': display(form.issue_date), 'card.expiry_date': display(form.expiry_date) },
                },
            };
        } catch {
            preview.value = null;
        } finally {
            previewLoading.value = false;
        }
    },
    { debounce: 250 },
);

function pickDocument(event: Event): void {
    document.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    const body = new FormData();
    body.append('template', form.template);
    body.append('reason', form.reason);
    for (const key of ['notes', 'issue_date', 'expiry_date', 'rf_card_no'] as const) {
        if (form[key].trim()) {
            body.append(key, form[key].trim());
        }
    }
    if (form.holder) {
        body.append('family_member', form.holder);
    }
    if (document.value && needs.value.document) {
        body.append('document', document.value);
    }
    try {
        const { data } = await http.post(`/cardholders/${props.cardholder.id}/cards`, body);
        ui.notify(t('cards.issued', { number: data.data.number }));
        open.value = false;
        emit('issued', data.data.id);
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
    <UiDialog v-model:open="open" :title="t('cards.issueTitle')" size="xl">
        <form id="issue-card-form" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]" @submit.prevent="submit">
            <div class="grid content-start gap-4 sm:grid-cols-2">
                <UiSelect
                    v-model="form.holder"
                    :label="t('cards.for')"
                    :options="[{ value: '', label: t('cards.holderSelf', { name: cardholder.full_name }) }, ...members.map((member) => ({ value: member.id, label: `${member.name} (${t(`cardholders.family.relations.${member.relation}`)})` }))]"
                    :error="errors.family_member"
                    data-field="holder"
                />
                <UiSelect
                    v-model="form.template"
                    :label="t('cards.template')"
                    required
                    :placeholder="usableTemplates.length ? '—' : t(forFamily ? 'cards.noFamilyTemplates' : 'cards.noTemplates')"
                    :options="usableTemplates.map((item) => ({ value: item.id, label: `${item.name} (${item.code})` }))"
                    :error="errors.template"
                    data-field="template"
                />
                <UiSelect v-model="form.reason" :label="t('cards.reason')" required :options="cardReasons.reasons.value.map((reason) => ({ value: reason.key, label: reasonLabel(reason.key) }))" :error="errors.reason" data-field="reason" />
                <UiInput v-model="form.rf_card_no" :label="t('cards.rfCardNo')" :maxlength="60" :error="errors.rf_card_no" />
                <UiInput v-model="form.issue_date" type="date" :label="t('cards.issueDate')" :error="errors.issue_date" />
                <UiInput v-model="form.expiry_date" type="date" :label="t('cards.expiryDate')" :hint="t('cards.expiryHint')" :error="errors.expiry_date" @input="expiryTouched = true" />
                <UiTextarea v-model="form.notes" class="sm:col-span-2" :label="t('cards.notes')" :required="needs.note" :maxlength="1000" :rows="2" :error="errors.notes" data-field="notes" />
                <div v-if="needs.document" class="space-y-1 sm:col-span-2">
                    <button type="button" class="flex w-full items-center gap-3 rounded-lg border border-dashed border-line px-4 py-2.5 text-left hover:border-brand hover:bg-surface-2" @click="documentInput?.click()">
                        <FileUp class="size-5 shrink-0 text-muted" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-fg">{{ document ? document.name : t('cards.document') }}</span>
                            <span class="block text-xs text-muted">{{ document ? formatBytes(document.size) : t('cards.documentHint') }}</span>
                        </span>
                    </button>
                    <input ref="documentInput" type="file" accept="application/pdf,image/png,image/jpeg,image/webp" class="hidden" data-document @change="pickDocument" />
                    <p v-if="errors.document" class="text-xs text-danger">{{ errors.document[0] }}</p>
                </div>
            </div>

            <!-- Preview of the real card -->
            <div class="space-y-3 rounded-xl border border-line bg-surface-2 p-4" data-issue-preview>
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ t('cards.preview') }}</p>
                    <Loader2 v-if="previewLoading" class="size-4 animate-spin text-muted" />
                </div>
                <template v-if="nextNumber">
                    <p class="font-mono text-sm text-fg" data-next-number>{{ nextNumber.number }}</p>
                    <UiBadge :tone="nextNumber.kept ? 'neutral' : 'success'">{{ t(nextNumber.kept ? 'cards.numberKept' : 'cards.numberNew') }}</UiBadge>
                </template>
                <CardRender v-if="preview" :layout="preview.layout" :values="preview.values" :box="300" />
                <p v-else class="text-sm text-muted">{{ t('cards.previewEmpty') }}</p>
                <p class="text-xs text-muted">{{ t('cards.previewHint') }}</p>
            </div>
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="issue-card-form" :loading="busy" :disabled="!form.template" data-issue-submit>{{ t('cards.issue') }}</UiButton>
        </template>
    </UiDialog>
</template>
