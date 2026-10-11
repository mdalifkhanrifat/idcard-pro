<script setup lang="ts">
import { ref, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { useI18n } from 'vue-i18n';
import { Eye, Loader2, Search, X } from 'lucide-vue-next';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import type { Cardholder, FamilyMember } from '@/types';

export interface PreviewData {
    fields: Record<string, string>;
    images: { photo: string | null; cardholder_signature: string | null; signatory_signature: string | null;
        /** Biometric element field => image link; null (or missing) prints the element's fallback. */
        biometrics?: Record<string, string | null> | [];
    };
}

/**
 * Chooses whose data the designer shows: made-up sample data, or a real cardholder
 * (and, on family templates, one of their family members) resolved by the server exactly as it will print.
 */
const props = defineProps<{ templateId: string; forFamily: boolean }>();
const emit = defineEmits<{ change: [data: PreviewData | null] }>();
const { t } = useI18n();
const ui = useUiStore();

const search = ref('');
const results = ref<Cardholder[]>([]);
const person = ref<Cardholder | null>(null);
const members = ref<FamilyMember[]>([]);
const memberId = ref('');
const loading = ref(false);

watchDebounced(
    search,
    async (value) => {
        if (value.trim().length < 2) {
            results.value = [];

            return;
        }
        const { data } = await http.get('/cardholders', { params: { 'filter[search]': value.trim(), per_page: 8 } });
        results.value = data.data;
    },
    { debounce: 300 },
);

async function load(): Promise<void> {
    if (!person.value) {
        emit('change', null);

        return;
    }
    loading.value = true;
    try {
        const params: Record<string, string> = { cardholder: person.value.id };
        if (memberId.value) {
            params.family_member = memberId.value;
        }
        const { data } = await http.get(`/card-templates/${props.templateId}/preview`, { params });
        emit('change', data.data);
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    } finally {
        loading.value = false;
    }
}

async function choose(cardholder: Cardholder): Promise<void> {
    person.value = cardholder;
    search.value = '';
    results.value = [];
    memberId.value = '';
    members.value = props.forFamily ? (await http.get(`/cardholders/${cardholder.id}/family-members`)).data.data : [];
    memberId.value = members.value[0]?.id ?? '';
    await load();
}

function clear(): void {
    person.value = null;
    members.value = [];
    memberId.value = '';
    emit('change', null);
}

watch(memberId, (value, old) => {
    if (person.value && old !== '' && value !== old) {
        load();
    }
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <Eye class="size-4 text-muted" />
        <span class="text-muted">{{ t('designer.previewWith') }}</span>
        <template v-if="person">
            <span class="inline-flex items-center gap-1 rounded-full bg-surface px-2.5 py-1 font-medium text-fg ring-1 ring-line" data-preview-person>
                {{ person.full_name }}<span v-if="person.employee_no" class="text-muted">· {{ person.employee_no }}</span>
                <button class="ml-1 text-muted hover:text-fg" :title="t('designer.backToSample')" data-preview-clear @click="clear"><X class="size-3.5" /></button>
            </span>
            <select v-if="forFamily" v-model="memberId" class="h-8 rounded-md border border-line bg-surface px-2 text-sm text-fg" data-preview-member>
                <option v-if="!members.length" value="">{{ t('designer.noFamilyMembers') }}</option>
                <option v-for="member in members" :key="member.id" :value="member.id">{{ member.name }}</option>
            </select>
            <Loader2 v-if="loading" class="size-4 animate-spin text-muted" />
        </template>
        <div v-else class="relative">
            <span class="inline-flex items-center gap-1 rounded-full bg-surface px-2.5 py-1 text-fg ring-1 ring-line">{{ t('designer.sampleData') }}</span>
            <span class="relative ml-2 inline-block">
                <Search class="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted" />
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('designer.findCardholder')"
                    class="h-8 w-56 rounded-md border border-line bg-surface pr-2 pl-7 text-sm text-fg focus:border-brand-ink focus:outline-none"
                    data-preview-search
                />
            </span>
            <ul v-if="results.length" class="absolute top-full right-0 z-20 mt-1 w-72 overflow-hidden rounded-lg border border-line bg-surface shadow-lg">
                <li v-for="item in results" :key="item.id">
                    <button class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-surface-2" data-preview-result @click="choose(item)">
                        <span class="truncate text-fg">{{ item.full_name }}</span>
                        <span class="shrink-0 text-xs text-muted">{{ item.employee_no }}</span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
