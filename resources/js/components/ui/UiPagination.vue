<script setup lang="ts">
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { formatNumber } from '@/lib/format';

const props = defineProps<{
    page: number;
    lastPage: number;
    total: number;
    from: number | null;
    to: number | null;
    /** Rows per page now, and the sizes to choose from (no choice shown without them). */
    perPage?: number;
    sizes?: number[];
}>();
const emit = defineEmits<{ change: [page: number]; perPage: [size: number] }>();
const { t } = useI18n();

// The current size is offered even when it is not one of the usual ones (e.g. from a link)
const sizeOptions = computed(() => (props.perPage && props.sizes?.length ? [...new Set([...props.sizes, props.perPage])].sort((a, b) => a - b) : []));
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-line px-5 py-3 text-sm">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span class="text-muted tabular-nums" data-pagination-showing>{{ total ? t('common.showing', { from: formatNumber(from ?? 0), to: formatNumber(to ?? 0), total: formatNumber(total) }) : '' }}</span>
            <label v-if="sizeOptions.length" class="flex items-center gap-2 text-muted">
                <span class="whitespace-nowrap">{{ t('common.perPage') }}</span>
                <select
                    class="h-8 rounded-md border border-line bg-surface px-2 text-sm text-fg tabular-nums focus:border-brand-ink focus:outline-none focus:ring-4 focus:ring-[var(--ring)]"
                    :value="perPage"
                    data-per-page
                    @change="emit('perPage', Number(($event.target as HTMLSelectElement).value))"
                >
                    <option v-for="size in sizeOptions" :key="size" :value="size">{{ formatNumber(size) }}</option>
                </select>
            </label>
        </div>
        <div class="flex items-center gap-1">
            <button
                class="inline-flex h-8 items-center gap-1 rounded-md px-2 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-40"
                :disabled="page <= 1"
                @click="emit('change', page - 1)"
            >
                <ChevronLeft class="size-4" />{{ t('common.previous') }}
            </button>
            <span class="px-2 whitespace-nowrap tabular-nums text-muted">{{ formatNumber(page) }} / {{ formatNumber(lastPage || 1) }}</span>
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
