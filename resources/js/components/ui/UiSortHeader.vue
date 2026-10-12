<script setup lang="ts">
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-vue-next';

const props = defineProps<{ field: string; sort: string; label: string }>();
const emit = defineEmits<{ toggle: [field: string] }>();
</script>

<template>
    <!-- Buttons do not inherit text-transform: follow the header row (e.g. uppercase) like the plain labels -->
    <button type="button" class="inline-flex items-center gap-1 [text-transform:inherit] hover:text-fg" @click="emit('toggle', props.field)">
        {{ label }}
        <ArrowUp v-if="sort === field" class="size-3.5" />
        <ArrowDown v-else-if="sort === `-${field}`" class="size-3.5" />
        <ArrowUpDown v-else class="size-3.5 opacity-40" />
    </button>
</template>
