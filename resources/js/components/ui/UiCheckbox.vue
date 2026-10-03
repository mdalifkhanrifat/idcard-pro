<script setup lang="ts">
defineProps<{ label?: string; description?: string; disabled?: boolean }>();
const model = defineModel<boolean | string[]>();
defineOptions({ inheritAttrs: false });
</script>

<template>
    <label class="flex cursor-pointer items-start gap-3" :class="disabled && 'cursor-not-allowed opacity-60'">
        <input
            v-model="model"
            type="checkbox"
            v-bind="$attrs"
            :disabled="disabled"
            class="mt-0.5 size-4 rounded border-line accent-[var(--brand)]"
        />
        <span v-if="label || description">
            <span class="block text-sm text-fg">{{ label }}</span>
            <span v-if="description" class="block text-xs text-muted">{{ description }}</span>
        </span>
    </label>
</template>
