<script setup lang="ts">
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-vue-next';

const props = defineProps<{ field: string; sort: string; label: string }>();
const emit = defineEmits<{ toggle: [field: string] }>();
</script>

<template>
    <button class="inline-flex items-center gap-1 hover:text-fg" @click="emit('toggle', props.field)">
        {{ label }}
        <ArrowUp v-if="sort === field" class="size-3.5" />
        <ArrowDown v-else-if="sort === `-${field}`" class="size-3.5" />
        <ArrowUpDown v-else class="size-3.5 opacity-40" />
    </button>
</template>
