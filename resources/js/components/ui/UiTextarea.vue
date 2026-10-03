<script setup lang="ts">
import { computed, useId } from 'vue';

const props = withDefaults(defineProps<{ label?: string; error?: string | string[]; hint?: string; rows?: number; required?: boolean; maxlength?: number }>(), { rows: 3 });

const model = defineModel<string | null>();
const id = useId();
const message = computed(() => (Array.isArray(props.error) ? props.error[0] : props.error));
</script>

<template>
    <div class="space-y-1.5">
        <label v-if="label" :for="id" class="block text-sm font-medium text-fg">
            {{ label }}<span v-if="required" class="text-danger"> *</span>
        </label>
        <textarea
            :id="id"
            v-model="model"
            :rows="rows"
            :required="required"
            :maxlength="maxlength"
            :aria-invalid="!!message"
            class="w-full rounded-lg border bg-surface px-3 py-2 text-sm text-fg placeholder:text-muted/70 transition focus:border-brand focus:outline-none focus:ring-4 focus:ring-[var(--ring)]"
            :class="message ? 'border-danger' : 'border-line'"
        />
        <p v-if="message || hint" class="text-xs" :class="message ? 'text-danger' : 'text-muted'">{{ message || hint }}</p>
    </div>
</template>
