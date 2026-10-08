<script setup lang="ts">
import { CheckCircle2, Info, X, XCircle } from 'lucide-vue-next';
import { useUiStore } from '@/stores/ui';

const ui = useUiStore();
const icons = { success: CheckCircle2, error: XCircle, info: Info };
</script>

<template>
    <div class="pointer-events-none fixed right-4 bottom-4 print:hidden z-[60] flex w-full max-w-sm flex-col gap-2" aria-live="polite">
        <TransitionGroup
            enter-from-class="translate-y-2 opacity-0"
            enter-active-class="transition duration-200"
            leave-to-class="opacity-0"
            leave-active-class="transition duration-150"
        >
            <div
                v-for="toast in ui.toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-start gap-3 rounded-xl border border-line bg-surface p-4 shadow-lg"
            >
                <component
                    :is="icons[toast.type]"
                    class="mt-0.5 size-5 shrink-0"
                    :class="{ 'text-success': toast.type === 'success', 'text-danger': toast.type === 'error', 'text-brand-ink': toast.type === 'info' }"
                />
                <p class="flex-1 text-sm text-fg">{{ toast.message }}</p>
                <button class="text-muted hover:text-fg" aria-label="Dismiss" @click="ui.dismiss(toast.id)"><X class="size-4" /></button>
            </div>
        </TransitionGroup>
    </div>
</template>
