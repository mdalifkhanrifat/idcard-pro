<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Loader2, Pencil, Trash2, UserRound } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import CardholderCards from './CardholderCards.vue';
import CardholderDocuments from './CardholderDocuments.vue';
import CardholderFamily from './CardholderFamily.vue';
import CardholderQualifications from './CardholderQualifications.vue';
import { useCustomFields } from '@/composables/useCustomFields';
import { useConfirm } from '@/composables/useConfirm';
import { http, toApiError } from '@/lib/http';
import { formatDate, formatDateTime } from '@/lib/format';
import { statusTone } from './cardholder';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { Cardholder, CardholderFile, LookupRef } from '@/types';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();
const customFields = useCustomFields();

const cardholder = ref<Cardholder | null>(null);
const earlierImages = ref<CardholderFile[]>([]);
const TABS = computed(() => ['details', ...(auth.can('cards.view') ? (['cards'] as const) : []), 'family', 'qualifications', 'documents'] as const);
type TabKey = 'details' | 'cards' | 'family' | 'qualifications' | 'documents';
const tab = ref<TabKey>(route.query.tab === 'cards' ? 'cards' : 'details');
const tabLabel = (key: TabKey) =>
    ({ details: t('cardholders.tabs.details'), cards: t('cards.tab'), family: t('cardholders.family.title'), qualifications: t('cardholders.qualifications.title'), documents: t('cardholders.files.document') })[key];

type Row = [label: string, value: string | null | undefined];

const lookup = (item: LookupRef | null | undefined) => (item ? (item.name_local && item.name_local !== item.name ? `${item.name} (${item.name_local})` : item.name) : null);
const label = (field: string) => t(`cardholders.fields.${field}`);

const sections = computed<{ key: string; title?: string; rows: Row[] }[]>(() => {
    const c = cardholder.value;
    if (!c) {
        return [];
    }
    const result = [
        {
            key: 'identity',
            rows: [
                [label('full_name_local'), c.full_name_local],
                [label('date_of_birth'), c.date_of_birth && formatDate(c.date_of_birth)],
                [label('gender'), c.gender && t(`cardholders.gender.${c.gender}`)],
                [label('marital_status'), c.marital_status && t(`cardholders.maritalStatus.${c.marital_status}`)],
                [label('nationality'), c.nationality],
                [label('national_id'), c.national_id],
                [label('passport_no'), c.passport_no],
                [label('blood_group_id'), lookup(c.blood_group)],
                [label('religion_id'), lookup(c.religion)],
            ],
        },
        {
            key: 'office',
            rows: [
                [label('employee_no'), c.employee_no],
                [label('secondary_no'), c.secondary_no],
                [label('employment_type'), t(`cardholders.employmentType.${c.employment_type}`)],
                [label('department_id'), lookup(c.department)],
                [label('unit_id'), lookup(c.unit)],
                [label('rank_id'), lookup(c.rank)],
                [label('designation_id'), lookup(c.designation)],
                [label('branch_id'), lookup(c.branch)],
                [label('trade_id'), lookup(c.trade)],
                [label('appointment_id'), lookup(c.appointment)],
                [label('joining_date'), c.joining_date && formatDate(c.joining_date)],
                [label('retirement_date'), c.retirement_date && formatDate(c.retirement_date)],
                [label('retired_id_no'), c.retired_id_no],
                [label('posting'), c.posting],
                [label('previous_office'), c.previous_office],
            ],
        },
        {
            key: 'contact',
            rows: [
                [label('email'), c.email],
                [label('personal_phone'), c.personal_phone],
                [label('emergency_phone'), c.emergency_phone],
                [label('present_address'), c.present_address],
                [label('permanent_address'), c.permanent_address],
                [label('region_id'), lookup(c.region)],
                [label('sub_region_id'), lookup(c.sub_region)],
                [label('postal_code'), c.postal_code],
            ],
        },
        {
            key: 'physical',
            rows: [
                [label('height'), c.height],
                [label('weight'), c.weight],
                [label('eye_color'), c.eye_color],
                [label('complexion'), c.complexion],
                [label('identification_mark'), c.identification_mark],
            ],
        },
        ...customFields.groups.value.map((group) => ({
            key: 'additional',
            title: group.name || t('cardholders.sections.additional'),
            rows: group.fields.map((field) => [customFields.label(field), customFields.display(field, c.custom_fields?.[field.key])]),
        })),
        { key: 'external', rows: [[label('company_name'), c.company_name], [label('company_address'), c.company_address], [label('company_phone'), c.company_phone]] },
        {
            key: 'history',
            rows: [
                [label('created_by'), c.created_by?.name],
                [label('created_at'), formatDateTime(c.created_at)],
                [label('updated_at'), formatDateTime(c.updated_at)],
            ],
        },
    ] as { key: string; title?: string; rows: Row[] }[];

    // Hide empty sections (e.g. external organization for staff) but keep the identity and office blocks.
    return result
        .map((section) => ({ ...section, rows: section.rows.filter(([, value]) => value) }))
        .filter((section) => section.rows.length || ['identity', 'office'].includes(section.key));
});

async function load(): Promise<void> {
    try {
        const id = route.params.id as string;
        const [, record, images] = await Promise.all([
            customFields.load(),
            http.get(`/cardholders/${id}`),
            http.get(`/cardholders/${id}/files`, { params: { include_replaced: 1 } }),
        ]);
        cardholder.value = record.data.data;
        earlierImages.value = (images.data.data as CardholderFile[]).filter((file) => !file.is_current && file.collection !== 'document');
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
        router.replace('/cardholders');
    }
}

async function remove(): Promise<void> {
    const c = cardholder.value!;
    if (!(await confirm({ title: t('common.areYouSure'), message: t('cardholders.deleteConfirm', { name: c.full_name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/cardholders/${c.id}`);
        ui.notify(t('common.deleted'));
        router.push('/cardholders');
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}

onMounted(load);
</script>

<template>
    <div v-if="!cardholder" class="flex justify-center py-20 text-muted"><Loader2 class="size-6 animate-spin" /></div>
    <div v-else>
        <RouterLink to="/cardholders" class="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-fg"><ArrowLeft class="size-4" />{{ t('cardholders.back') }}</RouterLink>

        <!-- Header -->
        <div class="mb-6 flex flex-col gap-5 rounded-xl border border-line bg-surface p-5 sm:flex-row sm:items-center">
            <img v-if="cardholder.photo" :src="cardholder.photo.url" alt="" class="h-32 w-24 shrink-0 rounded-lg border border-line object-cover" />
            <div v-else class="flex h-32 w-24 shrink-0 items-center justify-center rounded-lg bg-surface-2 text-muted"><UserRound class="size-8" /></div>
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ cardholder.full_name }}</h1>
                <p v-if="cardholder.full_name_local" class="text-muted">{{ cardholder.full_name_local }}</p>
                <p class="mt-1 text-sm text-muted">
                    <span v-if="cardholder.employee_no" class="font-mono">{{ cardholder.employee_no }}</span>
                    <template v-if="cardholder.employee_no && cardholder.designation"> · </template>{{ cardholder.designation?.name }}
                    <template v-if="cardholder.department"> · {{ cardholder.department.name }}</template>
                </p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <UiBadge :tone="statusTone(cardholder.status)">{{ t(`cardholders.status.${cardholder.status}`) }}</UiBadge>
                    <UiBadge>{{ t(`cardholders.employmentType.${cardholder.employment_type}`) }}</UiBadge>
                    <UiBadge v-if="cardholder.blood_group" tone="danger">{{ cardholder.blood_group.name }}</UiBadge>
                </div>
            </div>
            <div class="flex gap-2 self-start">
                <UiButton v-if="auth.can('cardholders.update')" variant="secondary" @click="router.push(`/cardholders/${cardholder.id}/edit`)"><Pencil class="size-4" />{{ t('common.edit') }}</UiButton>
                <UiButton v-if="auth.can('cardholders.delete')" variant="ghost" @click="remove"><Trash2 class="size-4" />{{ t('common.delete') }}</UiButton>
            </div>
        </div>

        <!-- Tabs -->
        <div class="mb-5 flex gap-1 overflow-x-auto border-b border-line" role="tablist">
            <button
                v-for="key in TABS"
                :key="key"
                type="button"
                role="tab"
                :aria-selected="tab === key"
                class="-mb-px border-b-2 px-3 py-2.5 text-sm whitespace-nowrap"
                :class="tab === key ? 'border-brand font-medium text-brand' : 'border-transparent text-muted hover:text-fg'"
                @click="tab = key"
            >
                {{ tabLabel(key) }}
            </button>
        </div>

        <div v-if="tab === 'details'" class="grid gap-6 lg:grid-cols-[1fr_300px]">
            <div class="grid gap-6 md:grid-cols-2">
                <UiCard v-for="section in sections" :key="`${section.key}-${section.title}`" :title="section.title ?? t(`cardholders.sections.${section.key}`)">
                    <dl class="space-y-3 text-sm">
                        <div v-for="[name, value] in section.rows" :key="name" class="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-3">
                            <dt class="text-muted">{{ name }}</dt>
                            <dd class="break-words whitespace-pre-line text-fg">{{ value }}</dd>
                        </div>
                        <p v-if="!section.rows.length" class="text-muted">—</p>
                    </dl>
                </UiCard>
            </div>
            <div class="space-y-6">
                <UiCard :title="t('cardholders.files.signature')">
                    <img v-if="cardholder.signature" :src="cardholder.signature.url" alt="" class="max-h-28 w-full object-contain" />
                    <p v-else class="text-sm text-muted">—</p>
                </UiCard>
                <UiCard v-if="earlierImages.length" :title="t('cardholders.files.history')">
                    <ul class="grid grid-cols-3 gap-2">
                        <li v-for="image in earlierImages" :key="image.id">
                            <a :href="image.url" target="_blank" rel="noopener" :title="`${t(`cardholders.files.${image.collection}`)} · ${formatDateTime(image.created_at)}`">
                                <img :src="image.url" alt="" class="aspect-[3/4] w-full rounded-md border border-line object-cover opacity-80 hover:opacity-100" />
                            </a>
                        </li>
                    </ul>
                </UiCard>
            </div>
        </div>

        <CardholderCards v-else-if="tab === 'cards'" :cardholder="cardholder" />
        <CardholderFamily v-else-if="tab === 'family'" :cardholder-id="cardholder.id" />
        <CardholderQualifications v-else-if="tab === 'qualifications'" :cardholder-id="cardholder.id" />
        <CardholderDocuments v-else :cardholder-id="cardholder.id" />
    </div>
</template>
