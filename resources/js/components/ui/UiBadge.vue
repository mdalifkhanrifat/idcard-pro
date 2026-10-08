<script setup lang="ts">
withDefaults(defineProps<{ tone?: 'neutral' | 'success' | 'danger' | 'warning' | 'brand' }>(), { tone: 'neutral' });
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="{
            'bg-surface-2 text-muted': tone === 'neutral',
            'bg-success/12 text-success': tone === 'success',
            'bg-danger/12 text-danger': tone === 'danger',
            'bg-warning/12 text-warning': tone === 'warning',
            'bg-brand/12 text-brand-ink': tone === 'brand',
        }"
    >
        <slot />
    </span>
</template>
