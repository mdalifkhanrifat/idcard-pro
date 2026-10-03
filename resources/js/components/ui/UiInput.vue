<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { Eye, EyeOff } from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        label?: string;
        type?: string;
        error?: string | string[];
        hint?: string;
        placeholder?: string;
        autocomplete?: string;
        required?: boolean;
        disabled?: boolean;
        inputmode?: 'text' | 'numeric' | 'email' | 'tel' | 'search';
        maxlength?: number;
        autofocus?: boolean;
    }>(),
    { type: 'text' },
);

const model = defineModel<string | number | null>();
const id = useId();
const reveal = ref(false);
const message = computed(() => (Array.isArray(props.error) ? props.error[0] : props.error));
const inputType = computed(() => (props.type === 'password' && reveal.value ? 'text' : props.type));
</script>

<template>
    <div class="space-y-1.5">
        <label v-if="label" :for="id" class="block text-sm font-medium text-fg">
            {{ label }}<span v-if="required" class="text-danger"> *</span>
        </label>
        <div class="relative">
            <input
                :id="id"
                v-model="model"
                :type="inputType"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :required="required"
                :disabled="disabled"
                :inputmode="inputmode"
                :maxlength="maxlength"
                :autofocus="autofocus"
                :aria-invalid="!!message"
                :aria-describedby="message || hint ? `${id}-desc` : undefined"
                class="h-10 w-full rounded-lg border bg-surface px-3 text-sm text-fg placeholder:text-muted/70 transition focus:border-brand focus:outline-none focus:ring-4 focus:ring-[var(--ring)] disabled:opacity-60"
                :class="[message ? 'border-danger' : 'border-line', type === 'password' && 'pr-10']"
            />
            <button
                v-if="type === 'password'"
                type="button"
                class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-muted hover:text-fg"
                :aria-label="reveal ? 'Hide password' : 'Show password'"
                @click="reveal = !reveal"
            >
                <component :is="reveal ? EyeOff : Eye" class="size-4" />
            </button>
        </div>
        <p v-if="message || hint" :id="`${id}-desc`" class="text-xs" :class="message ? 'text-danger' : 'text-muted'">
            {{ message || hint }}
        </p>
    </div>
</template>
