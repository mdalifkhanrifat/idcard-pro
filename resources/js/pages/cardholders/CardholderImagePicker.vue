<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ImagePlus, RotateCcw } from 'lucide-vue-next';

/**
 * Picks an image and previews it locally; the parent uploads it after the record is saved.
 */
const props = defineProps<{ label: string; currentUrl?: string | null; maxKb: number; aspect?: 'portrait' | 'wide' }>();
const file = defineModel<File | null>({ default: null });
const { t } = useI18n();

const input = ref<HTMLInputElement | null>(null);
const preview = ref<string | null>(null);
const error = ref('');
const shown = computed(() => preview.value ?? props.currentUrl ?? null);

watch(file, (value) => {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }
    preview.value = value ? URL.createObjectURL(value) : null;
});
onBeforeUnmount(() => preview.value && URL.revokeObjectURL(preview.value));

function choose(event: Event): void {
    const picked = (event.target as HTMLInputElement).files?.[0] ?? null;
    error.value = '';
    if (picked && !['image/jpeg', 'image/png', 'image/webp'].includes(picked.type)) {
        error.value = t('cardholders.files.imageType');
    } else if (picked && picked.size > props.maxKb * 1024) {
        error.value = t('cardholders.files.tooLarge', { size: Math.round(props.maxKb / 1024) });
    } else {
        file.value = picked;
    }
    (event.target as HTMLInputElement).value = '';
}
</script>

<template>
    <div class="space-y-2">
        <p class="text-sm font-medium text-fg">{{ label }}</p>
        <button
            type="button"
            class="group relative flex w-full items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-line bg-surface-2/40 transition hover:border-brand"
            :class="aspect === 'wide' ? 'aspect-[5/2]' : 'aspect-[3/4] max-w-56'"
            @click="input?.click()"
        >
            <img v-if="shown" :src="shown" alt="" class="size-full object-contain" />
            <span v-else class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted">
                <ImagePlus class="size-6" />{{ t('cardholders.files.choose') }}
            </span>
            <span v-if="shown" class="absolute inset-x-0 bottom-0 bg-black/55 py-1.5 text-xs text-white opacity-0 transition group-hover:opacity-100">
                {{ t('cardholders.files.change') }}
            </span>
        </button>
        <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="choose" />
        <div class="flex items-center gap-2 text-xs">
            <span v-if="file" class="text-brand">{{ t('cardholders.files.pending') }}</span>
            <button v-if="file" type="button" class="inline-flex items-center gap-1 text-muted hover:text-fg" @click="file = null">
                <RotateCcw class="size-3" />{{ t('common.reset') }}
            </button>
            <span v-if="!file" class="text-muted">{{ t('cardholders.files.rules', { size: Math.round(maxKb / 1024) }) }}</span>
        </div>
        <p v-if="error" class="text-xs text-danger">{{ error }}</p>
    </div>
</template>
