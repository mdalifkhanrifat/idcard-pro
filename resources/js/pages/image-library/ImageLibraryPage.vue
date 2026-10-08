<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { useI18n } from 'vue-i18n';
import { Loader2, Pencil, Replace, Search, Trash2, Upload } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import LibraryImageGrid from './LibraryImageGrid.vue';
import { useConfirm } from '@/composables/useConfirm';
import { http, toApiError } from '@/lib/http';
import { formatBytes } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { LibraryImage } from '@/types';

/**
 * The organization's image library: logos and backgrounds uploaded once and linked from any template.
 * Images never change; a new logo replaces the old one in every template at once.
 */
const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const images = ref<LibraryImage[]>([]);
const loading = ref(true);
const search = ref('');
const selected = ref<LibraryImage | null>(null);
const uploading = ref(0);
const fileInput = useTemplateRef<HTMLInputElement>('file');

async function load(): Promise<void> {
    loading.value = true;
    try {
        images.value = (await http.get('/library-images', { params: search.value ? { 'filter[search]': search.value } : {} })).data.data;
        selected.value = images.value.find((image) => image.id === selected.value?.id) ?? null;
    } finally {
        loading.value = false;
    }
}

watchDebounced(search, load, { debounce: 300 });
onMounted(load);

/** Several files at once, one request each; failures are reported per file. */
async function upload(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = [...(input.files ?? [])];
    input.value = '';
    uploading.value = files.length;
    for (const file of files) {
        const body = new FormData();
        body.append('file', file);
        try {
            await http.post('/library-images', body);
        } catch (error) {
            const apiError = toApiError(error);
            ui.notify(`${file.name}: ${apiError.errors.file?.[0] ?? apiError.message}`, 'error');
        } finally {
            uploading.value--;
        }
    }
    await load();
}

/* Rename */
const renameOpen = ref(false);
const newName = ref('');
const renameErrors = ref<Record<string, string[]>>({});

function openRename(): void {
    newName.value = selected.value?.name ?? '';
    renameErrors.value = {};
    renameOpen.value = true;
}

async function rename(): Promise<void> {
    try {
        await http.put(`/library-images/${selected.value!.id}`, { name: newName.value.trim() });
        renameOpen.value = false;
        ui.notify(t('common.saved'));
        await load();
    } catch (error) {
        renameErrors.value = toApiError(error).errors;
    }
}

/* Replace in every template */
const replaceOpen = ref(false);
const replacement = ref<LibraryImage | null>(null);
const others = computed(() => images.value.filter((image) => image.id !== selected.value?.id));
const replacing = ref(false);

async function replaceEverywhere(): Promise<void> {
    if (!selected.value || !replacement.value) {
        return;
    }
    replacing.value = true;
    try {
        const { data } = await http.post(`/library-images/${selected.value.id}/replace`, { with: replacement.value.id });
        replaceOpen.value = false;
        ui.notify(t('library.replaced', { count: data.data.templates }));
        selected.value = replacement.value;
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    } finally {
        replacing.value = false;
    }
}

async function remove(): Promise<void> {
    const image = selected.value;
    if (!image || !(await confirm({ title: t('library.deleteConfirm', { name: image.name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/library-images/${image.id}`);
        ui.notify(t('common.deleted'));
        selected.value = null;
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('library.title')" :subtitle="t('library.subtitle')">
            <UiButton v-if="auth.can('images.create')" class="shrink-0 whitespace-nowrap" :loading="uploading > 0" data-upload-images @click="fileInput?.click()"><Upload class="size-4" />{{ t('library.upload') }}</UiButton>
            <input ref="file" type="file" accept="image/png,image/jpeg,image/webp" multiple class="hidden" data-library-file @change="upload" />
        </UiPageHeader>

        <div class="grid gap-4 lg:grid-cols-[1fr_18rem]">
            <div class="min-w-0 rounded-xl border border-line bg-surface">
                <div class="border-b border-line p-4">
                    <div class="relative">
                        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                        <input
                            v-model="search"
                            type="search"
                            :placeholder="t('library.searchPlaceholder')"
                            class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                        />
                    </div>
                </div>
                <div class="p-4" :class="loading && 'opacity-50'">
                    <LibraryImageGrid v-if="images.length" :images="images" :selected-id="selected?.id" @pick="selected = $event" />
                    <UiEmpty v-else-if="!loading" :title="t('library.empty')" :hint="t('library.emptyHint')" />
                    <p v-if="uploading" class="mt-3 flex items-center gap-2 text-sm text-muted"><Loader2 class="size-4 animate-spin" />{{ t('library.uploading', { count: uploading }) }}</p>
                </div>
            </div>

            <!-- Details of the selected image -->
            <aside class="h-fit rounded-xl border border-line bg-surface p-4 text-sm lg:sticky lg:top-4" data-image-details>
                <template v-if="selected">
                    <p class="font-semibold break-words text-fg">{{ selected.name }}</p>
                    <dl class="mt-3 space-y-1.5 text-xs">
                        <div class="flex justify-between gap-2"><dt class="text-muted">{{ t('library.size') }}</dt><dd class="text-fg">{{ t('library.dimensions', { width: selected.width, height: selected.height }) }} · {{ formatBytes(selected.size) }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">{{ t('library.usedIn') }}</dt><dd class="text-fg" data-used-in>{{ t('library.templates', { count: selected.templates_count ?? 0 }, selected.templates_count ?? 0) }}</dd></div>
                    </dl>
                    <div class="mt-4 space-y-2">
                        <UiButton v-if="auth.can('images.update')" variant="secondary" block data-rename @click="openRename"><Pencil class="size-4" />{{ t('library.rename') }}</UiButton>
                        <UiButton
                            v-if="auth.can('images.update') && auth.can('templates.update') && selected.templates_count"
                            variant="secondary"
                            block
                            data-replace
                            @click="replacement = null; replaceOpen = true"
                        >
                            <Replace class="size-4" />{{ t('library.replace') }}
                        </UiButton>
                        <UiButton v-if="auth.can('images.delete')" variant="danger" block :disabled="!!selected.templates_count" data-delete @click="remove"><Trash2 class="size-4" />{{ t('common.delete') }}</UiButton>
                        <p v-if="selected.templates_count" class="text-xs text-muted">{{ t('library.inUseHint') }}</p>
                    </div>
                </template>
                <p v-else class="text-muted">{{ t('library.selectHint') }}</p>
            </aside>
        </div>

        <UiDialog v-model:open="renameOpen" :title="t('library.rename')">
            <form id="rename-form" @submit.prevent="rename">
                <UiInput v-model="newName" :label="t('library.name')" required :maxlength="100" :error="renameErrors.name" />
            </form>
            <template #footer>
                <UiButton variant="secondary" @click="renameOpen = false">{{ t('common.cancel') }}</UiButton>
                <UiButton type="submit" form="rename-form">{{ t('common.save') }}</UiButton>
            </template>
        </UiDialog>

        <UiDialog v-model:open="replaceOpen" :title="t('library.replaceTitle', { name: selected?.name ?? '' })" :description="t('library.replaceHint', { count: selected?.templates_count ?? 0 })" size="lg">
            <LibraryImageGrid v-if="others.length" :images="others" :selected-id="replacement?.id" compact @pick="replacement = $event" />
            <p v-else class="text-sm text-muted">{{ t('library.replaceNone') }}</p>
            <template #footer>
                <UiButton variant="secondary" @click="replaceOpen = false">{{ t('common.cancel') }}</UiButton>
                <UiButton :disabled="!replacement" :loading="replacing" data-confirm-replace @click="replaceEverywhere">{{ t('library.replaceConfirm') }}</UiButton>
            </template>
        </UiDialog>
    </div>
</template>
