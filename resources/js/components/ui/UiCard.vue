<script setup lang="ts">
withDefaults(defineProps<{ title?: string; description?: string; padded?: boolean }>(), { padded: true });
</script>

<template>
    <section class="rounded-xl border border-line bg-surface shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
        <!-- The actions go below the title on a narrow screen rather than squeeze its description -->
        <header v-if="title || $slots.actions" class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-line px-5 py-4">
            <div class="min-w-0 flex-[1_1_16rem]">
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
