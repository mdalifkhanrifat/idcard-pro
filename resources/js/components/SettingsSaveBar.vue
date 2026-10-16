<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';

/** Stays at the bottom of a settings page while there are unsaved changes (beside the side menu on wide screens). */
defineProps<{ saving: boolean }>();
const emit = defineEmits<{ save: []; discard: [] }>();
const { t } = useI18n();
</script>

<template>
    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 shadow-[0_-4px_12px_rgba(16,24,40,0.08)] backdrop-blur lg:left-64" data-save-bar>
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-6">
            <p class="text-sm text-fg">{{ t('common.unsaved.bar') }}</p>
            <div class="flex gap-2">
                <UiButton variant="secondary" :disabled="saving" data-discard @click="emit('discard')">{{ t('common.unsaved.discard') }}</UiButton>
                <UiButton :loading="saving" data-save @click="emit('save')">{{ t('common.save') }}</UiButton>
            </div>
        </div>
    </div>
</template>
