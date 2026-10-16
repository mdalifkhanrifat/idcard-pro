<script setup lang="ts">
import { useId } from 'vue';

/** One of a few choices, each with an optional line of explanation; the field's name and error around it. */
defineProps<{ label?: string; options: { value: string; label: string; description?: string }[]; error?: string | string[]; hint?: string; disabled?: boolean }>();
const model = defineModel<string>({ required: true });
const name = useId();
</script>

<template>
    <div role="radiogroup" :aria-label="label" class="space-y-1.5">
        <p v-if="label" class="text-sm font-medium text-fg">{{ label }}</p>
        <div class="space-y-2">
            <label v-for="option in options" :key="option.value" class="flex cursor-pointer items-start gap-3 text-sm" :class="disabled && 'cursor-not-allowed opacity-60'">
                <input v-model="model" type="radio" :name="name" :value="option.value" :disabled="disabled" class="mt-0.5 size-4 accent-[var(--brand)]" />
                <span>
                    <span class="block text-fg">{{ option.label }}</span>
                    <span v-if="option.description" class="block text-xs text-muted">{{ option.description }}</span>
                </span>
            </label>
        </div>
        <p v-if="hint && !error" class="text-xs text-muted">{{ hint }}</p>
        <p v-if="error" class="text-xs text-danger">{{ Array.isArray(error) ? error[0] : error }}</p>
    </div>
</template>
