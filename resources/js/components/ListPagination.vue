<script setup lang="ts">
import { computed, type Ref } from 'vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import type { PageMeta } from '@/composables/useListQuery';
import { useAuthStore } from '@/stores/auth';

/**
 * Paging of a list from useListQuery: pages, and rows per page from the sizes the server offers (legacy lists
 * offered 10 to 2000).
 */
const props = defineProps<{
    list: { meta: Ref<PageMeta>; page: Ref<number>; perPage: Ref<number>; setPerPage: (size: number) => void };
}>();

const auth = useAuthStore();
const sizes = computed(() => auth.session?.lists?.page_sizes ?? []);
const meta = computed(() => props.list.meta.value);
</script>

<template>
    <UiPagination
        :page="meta.current_page"
        :last-page="meta.last_page"
        :total="meta.total"
        :from="meta.from"
        :to="meta.to"
        :per-page="list.perPage.value"
        :sizes="sizes"
        @change="list.page.value = $event"
        @per-page="list.setPerPage($event)"
    />
</template>
