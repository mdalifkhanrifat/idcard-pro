<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ExternalLink, FileText, Image as ImageIcon, Trash2, Upload } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useConfirm } from '@/composables/useConfirm';
import { http, toApiError } from '@/lib/http';
import { formatBytes, formatDateTime } from '@/lib/format';
import { FILE_LIMITS } from './cardholder';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { CardholderFile } from '@/types';

const props = defineProps<{ cardholderId: string }>();
const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const documents = ref<CardholderFile[]>([]);
const loading = ref(true);
const title = ref('');
const picked = ref<File | null>(null);
const input = ref<HTMLInputElement | null>(null);
const error = ref('');
const busy = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    try {
        documents.value = (await http.get(`/cardholders/${props.cardholderId}/files`, { params: { collection: 'document' } })).data.data;
    } finally {
        loading.value = false;
    }
}

function choose(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    error.value = file && file.size > FILE_LIMITS.document * 1024 ? t('cardholders.files.tooLarge', { size: FILE_LIMITS.document / 1024 }) : '';
    picked.value = error.value ? null : file;
}

async function upload(): Promise<void> {
    if (!picked.value) {
        return;
    }
    busy.value = true;
    const body = new FormData();
    body.append('collection', 'document');
    body.append('file', picked.value);
    if (title.value.trim()) {
        body.append('title', title.value.trim());
    }
    try {
        await http.post(`/cardholders/${props.cardholderId}/files`, body);
        ui.notify(t('cardholders.files.uploaded'));
        title.value = '';
        picked.value = null;
        if (input.value) {
            input.value.value = '';
        }
        await load();
    } catch (e) {
        const apiError = toApiError(e);
        error.value = apiError.errors.file?.[0] ?? apiError.errors.title?.[0] ?? apiError.message;
    } finally {
        busy.value = false;
    }
}

async function remove(file: CardholderFile): Promise<void> {
    if (!(await confirm({ title: t('cardholders.files.deleteConfirm', { name: file.title ?? file.original_name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/cardholders/${props.cardholderId}/files/${file.id}`);
        ui.notify(t('common.deleted'));
        await load();
    } catch (e) {
        ui.notify(toApiError(e).message, 'error');
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-5">
        <form v-if="auth.can('cardholders.update')" class="grid gap-3 rounded-xl border border-dashed border-line p-4 md:grid-cols-[1fr_1fr_auto] md:items-end" @submit.prevent="upload">
            <UiInput v-model="title" :label="t('cardholders.files.documentTitle')" :maxlength="255" />
            <div class="space-y-1.5">
                <p class="text-sm font-medium text-fg">{{ t('cardholders.files.document') }}</p>
                <input
                    ref="input"
                    type="file"
                    accept="application/pdf,image/jpeg,image/png,image/webp"
                    class="block w-full text-sm text-muted file:mr-3 file:h-10 file:rounded-lg file:border file:border-line file:bg-surface-2 file:px-3 file:text-fg"
                    @change="choose"
                />
            </div>
            <UiButton type="submit" :loading="busy" :disabled="!picked"><Upload class="size-4" />{{ t('cardholders.files.addDocument') }}</UiButton>
            <p class="text-xs md:col-span-3" :class="error ? 'text-danger' : 'text-muted'">{{ error || t('cardholders.files.documentRules', { size: FILE_LIMITS.document / 1024 }) }}</p>
        </form>

        <ul v-if="documents.length" class="divide-y divide-line rounded-xl border border-line">
            <li v-for="doc in documents" :key="doc.id" class="flex items-center gap-3 px-4 py-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-surface-2 text-muted">
                    <component :is="doc.mime_type === 'application/pdf' ? FileText : ImageIcon" class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-fg">{{ doc.title ?? doc.original_name }}</p>
                    <p class="truncate text-xs text-muted">
                        <template v-if="doc.title">{{ doc.original_name }} · </template>{{ formatBytes(doc.size) }} · {{ formatDateTime(doc.created_at) }}
                        <template v-if="doc.uploaded_by"> · {{ doc.uploaded_by.name }}</template>
                    </p>
                </div>
                <a :href="doc.url" target="_blank" rel="noopener" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('cardholders.files.open')">
                    <ExternalLink class="size-4" />
                </a>
                <button v-if="auth.can('cardholders.update')" class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger" :aria-label="t('common.delete')" @click="remove(doc)">
                    <Trash2 class="size-4" />
                </button>
            </li>
        </ul>
        <UiEmpty v-else-if="!loading" :title="t('cardholders.files.noDocuments')" />
    </div>
</template>
