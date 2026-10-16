<script setup lang="ts" generic="T extends string">
/** A page's tabs as a line under the header; one tab draws nothing. Names come from the page. */
defineProps<{ tabs: T[]; label: (key: T) => string }>();
const model = defineModel<T>({ required: true });
</script>

<template>
    <div v-if="tabs.length > 1" role="tablist" class="mb-6 flex gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-line)]">
        <button
            v-for="key in tabs"
            :key="key"
            type="button"
            role="tab"
            :aria-selected="model === key"
            class="border-b-2 px-3 py-2 text-sm whitespace-nowrap"
            :class="model === key ? 'border-brand-ink font-medium text-brand-ink' : 'border-transparent text-muted hover:text-fg'"
            :data-tab-button="key"
            @click="model = key"
        >
            {{ label(key) }}
        </button>
    </div>
</template>
