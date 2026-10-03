<script setup lang="ts">
import { SearchX } from 'lucide-vue-next';

defineProps<{ title: string; hint?: string }>();
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
        <div class="mb-3 rounded-full bg-surface-2 p-3 text-muted"><SearchX class="size-6" /></div>
        <p class="font-medium text-fg">{{ title }}</p>
        <p v-if="hint" class="mt-1 max-w-sm text-sm text-muted">{{ hint }}</p>
        <div class="mt-4"><slot /></div>
    </div>
</template>
