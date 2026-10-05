<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { LibraryImage } from '@/types';

/**
 * Image tiles on a checkerboard (so transparent logos show), with name and size.
 * Used by the library page and the pickers in the designer and the replace dialog.
 */
defineProps<{ images: LibraryImage[]; selectedId?: string | null; compact?: boolean }>();
const emit = defineEmits<{ pick: [image: LibraryImage] }>();
const { t } = useI18n();
</script>

<template>
    <ul class="grid gap-3" :class="compact ? 'grid-cols-3 sm:grid-cols-4' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5'">
        <li v-for="image in images" :key="image.id" class="min-w-0" :data-library-image="image.id">
            <button
                type="button"
                class="group block w-full overflow-hidden rounded-lg border text-left focus:ring-2 focus:ring-[var(--ring)] focus:outline-none"
                :class="selectedId === image.id ? 'border-brand ring-2 ring-[var(--ring)]' : 'border-line hover:border-brand'"
                :title="image.name"
                @click="emit('pick', image)"
            >
                <span class="checkerboard flex aspect-[4/3] items-center justify-center p-2">
                    <img :src="image.url" alt="" class="max-h-full max-w-full object-contain" loading="lazy" draggable="false" />
                </span>
                <span class="block border-t border-line px-2 py-1.5">
                    <span class="block truncate text-xs font-medium text-fg">{{ image.name }}</span>
                    <span v-if="!compact" class="block text-[11px] text-muted">{{ t('library.dimensions', { width: image.width, height: image.height }) }}</span>
                </span>
            </button>
            <slot :image="image" />
        </li>
    </ul>
</template>

<style scoped>
.checkerboard {
    background-color: #ffffff;
    background-image:
        linear-gradient(45deg, #e5e7eb 25%, transparent 25%), linear-gradient(-45deg, #e5e7eb 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, #e5e7eb 75%), linear-gradient(-45deg, transparent 75%, #e5e7eb 75%);
    background-size: 16px 16px;
    background-position: 0 0, 0 8px, 8px -8px, -8px 0;
}
</style>
