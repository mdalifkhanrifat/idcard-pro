<script setup lang="ts">
withDefaults(defineProps<{ title?: string; description?: string; padded?: boolean }>(), { padded: true });
</script>

<template>
    <section class="rounded-xl border border-line bg-surface shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
        <header v-if="title || $slots.actions" class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
            <div>
                <h2 class="text-base font-semibold text-fg">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-sm text-muted">{{ description }}</p>
            </div>
            <slot name="actions" />
        </header>
        <div :class="padded === false ? '' : 'p-5'">
            <slot />
        </div>
    </section>
</template>
