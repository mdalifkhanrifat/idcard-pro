<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        size?: 'sm' | 'md';
        type?: 'button' | 'submit';
        loading?: boolean;
        disabled?: boolean;
        block?: boolean;
    }>(),
    { variant: 'primary', size: 'md', type: 'button', loading: false, disabled: false, block: false },
);
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        class="inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60"
        :class="[
            size === 'sm' ? 'h-8 px-3 text-sm' : 'h-10 px-4 text-sm',
            block && 'w-full',
            {
                'bg-brand text-brand-contrast shadow-sm hover:brightness-110': variant === 'primary',
                'border border-line bg-surface text-fg hover:bg-surface-2': variant === 'secondary',
                'text-muted hover:bg-surface-2 hover:text-fg': variant === 'ghost',
                'bg-danger text-white hover:brightness-110': variant === 'danger',
            },
        ]"
    >
        <Loader2 v-if="loading" class="size-4 animate-spin" aria-hidden="true" />
        <slot />
    </button>
</template>
