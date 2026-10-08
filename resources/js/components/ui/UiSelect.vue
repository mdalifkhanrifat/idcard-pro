<script setup lang="ts">
import { computed, useId } from 'vue';

const props = defineProps<{
    label?: string;
    options: { value: string | number; label: string }[];
    placeholder?: string;
    error?: string | string[];
    required?: boolean;
}>();

const model = defineModel<string | number | null>();
const id = useId();
const message = computed(() => (Array.isArray(props.error) ? props.error[0] : props.error));
</script>

<template>
    <div class="space-y-1.5">
        <label v-if="label" :for="id" class="block text-sm font-medium text-fg">
            {{ label }}<span v-if="required" class="text-danger"> *</span>
        </label>
        <select
            :id="id"
            v-model="model"
            :required="required"
            class="h-10 w-full rounded-lg border bg-surface px-3 text-sm text-fg focus:border-brand-ink focus:outline-none focus:ring-4 focus:ring-[var(--ring)]"
            :class="message ? 'border-danger' : 'border-line'"
        >
            <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <p v-if="message" class="text-xs text-danger">{{ message }}</p>
    </div>
</template>
