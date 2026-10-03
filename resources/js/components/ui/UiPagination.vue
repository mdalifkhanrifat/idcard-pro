<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

defineProps<{ page: number; lastPage: number; total: number; from: number | null; to: number | null }>();
const emit = defineEmits<{ change: [page: number] }>();
const { t } = useI18n();
</script>

<template>
    <div class="flex items-center justify-between gap-4 border-t border-line px-5 py-3 text-sm">
        <span class="text-muted">{{ total ? t('common.showing', { from, to, total }) : '' }}</span>
        <div class="flex items-center gap-1">
            <button
                class="inline-flex h-8 items-center gap-1 rounded-md px-2 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-40"
                :disabled="page <= 1"
                @click="emit('change', page - 1)"
            >
                <ChevronLeft class="size-4" />{{ t('common.previous') }}
            </button>
            <span class="px-2 tabular-nums text-muted">{{ page }} / {{ lastPage || 1 }}</span>
            <button
                class="inline-flex h-8 items-center gap-1 rounded-md px-2 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-40"
                :disabled="page >= lastPage"
                @click="emit('change', page + 1)"
            >
                {{ t('common.next') }}<ChevronRight class="size-4" />
            </button>
        </div>
    </div>
</template>
