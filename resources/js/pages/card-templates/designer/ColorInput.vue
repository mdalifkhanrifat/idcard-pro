<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { X } from 'lucide-vue-next';

/**
 * Colour picker for a style key; empty means "not set" (default colour, or no fill).
 */
defineProps<{ label: string; placeholder?: string; name?: string }>();
const model = defineModel<string | undefined>();
const { t } = useI18n();
</script>

<template>
    <label class="block min-w-0 space-y-1">
        <span class="block truncate text-xs font-medium text-muted">{{ label }}</span>
        <span class="flex items-center gap-1">
            <input
                type="color"
                :value="model ?? placeholder ?? '#ffffff'"
                class="h-8 w-full min-w-0 cursor-pointer rounded-md border border-line bg-surface p-0.5"
                :class="!model && 'opacity-40'"
                :data-colour="name"
                @input="model = ($event.target as HTMLInputElement).value"
            />
            <button v-if="model" type="button" class="shrink-0 text-muted hover:text-fg" :title="t('designer.clearColour')" @click.prevent="model = undefined"><X class="size-3.5" /></button>
        </span>
    </label>
</template>
