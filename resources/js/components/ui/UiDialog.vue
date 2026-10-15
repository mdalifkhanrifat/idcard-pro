<script setup lang="ts">
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { X } from 'lucide-vue-next';

/** `fullOnPhone`: the whole screen below the sm breakpoint (for dialogs with much to do, e.g. an editor). */
withDefaults(defineProps<{ title: string; description?: string; size?: 'md' | 'lg' | 'xl' | '2xl'; fullOnPhone?: boolean }>(), { size: 'md' });
const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-40 bg-black/40 backdrop-blur-[2px] data-[state=open]:animate-in" />
            <DialogContent
                class="fixed top-1/2 left-1/2 z-50 flex max-h-[90vh] w-[calc(100vw-2rem)] -translate-x-1/2 -translate-y-1/2 flex-col rounded-2xl border border-line bg-surface shadow-2xl focus:outline-none"
                :class="[
                    { 'max-w-lg': size === 'md', 'max-w-2xl': size === 'lg', 'max-w-4xl': size === 'xl', 'max-w-6xl': size === '2xl' },
                    fullOnPhone && 'max-sm:inset-0 max-sm:h-dvh max-sm:max-h-none max-sm:w-full max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none',
                ]"
            >
                <div class="flex items-start justify-between gap-4 border-b border-line px-4 py-4 sm:px-6">
                    <div>
                        <DialogTitle class="text-lg font-semibold text-fg">{{ title }}</DialogTitle>
                        <DialogDescription v-if="description" class="mt-1 text-sm text-muted">{{ description }}</DialogDescription>
                    </div>
                    <DialogClose class="rounded-md p-1 text-muted hover:bg-surface-2 hover:text-fg" aria-label="Close">
                        <X class="size-5" />
                    </DialogClose>
                </div>
                <div class="flex-1 overflow-y-auto px-4 py-5 sm:px-6"><slot /></div>
                <div v-if="$slots.footer" class="flex justify-end gap-2 border-t border-line px-4 py-4 sm:px-6"><slot name="footer" /></div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
