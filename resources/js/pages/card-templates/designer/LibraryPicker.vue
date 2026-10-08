<script setup lang="ts">
import { ref, useTemplateRef, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { useI18n } from 'vue-i18n';
import { Loader2, Search, Upload } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import LibraryImageGrid from '@/pages/image-library/LibraryImageGrid.vue';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { LibraryImage } from '@/types';

/**
 * Pick an image from the organization library for a logo, background or fallback image;
 * new images can be added to the library from here.
 */
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ pick: [image: LibraryImage] }>();
const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();

const images = ref<LibraryImage[]>([]);
const loading = ref(false);
const search = ref('');
const uploading = ref(false);
const fileInput = useTemplateRef<HTMLInputElement>('file');

async function load(): Promise<void> {
    loading.value = true;
    try {
        images.value = (await http.get('/library-images', { params: search.value ? { 'filter[search]': search.value } : {} })).data.data;
    } finally {
        loading.value = false;
    }
}

watch(open, (value) => value && load());
watchDebounced(search, load, { debounce: 300 });

function pick(image: LibraryImage): void {
    emit('pick', image);
    open.value = false;
}

async function upload(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) {
        return;
    }
    uploading.value = true;
    try {
        const body = new FormData();
        body.append('file', file);
        pick((await http.post('/library-images', body)).data.data);
    } catch (error) {
        const apiError = toApiError(error);
        ui.notify(apiError.errors.file?.[0] ?? apiError.message, 'error');
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <UiDialog v-model:open="open" :title="t('designer.chooseFromLibrary')" size="lg">
        <div class="space-y-3" data-library-picker>
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('library.searchPlaceholder')"
                        class="h-9 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                    />
                </div>
                <UiButton v-if="auth.can('images.create')" variant="secondary" class="shrink-0 whitespace-nowrap" :loading="uploading" @click="fileInput?.click()"><Upload class="size-4" />{{ t('library.upload') }}</UiButton>
                <input ref="file" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" data-picker-file @change="upload" />
            </div>
            <LibraryImageGrid v-if="images.length" :images="images" compact @pick="pick" />
            <p v-else-if="loading" class="flex items-center gap-2 text-sm text-muted"><Loader2 class="size-4 animate-spin" /></p>
            <p v-else class="text-sm text-muted">{{ t('designer.libraryEmpty') }}</p>
        </div>
    </UiDialog>
</template>
