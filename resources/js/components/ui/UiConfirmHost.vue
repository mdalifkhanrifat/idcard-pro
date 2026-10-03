<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from '@/composables/useConfirm';
import UiButton from './UiButton.vue';
import UiDialog from './UiDialog.vue';

const { current, settle } = useConfirm();
const { t } = useI18n();
const open = computed({
    get: () => current.value !== null,
    set: (value) => !value && settle(false),
});
</script>

<template>
    <UiDialog v-model:open="open" :title="current?.title ?? ''">
        <p class="text-sm text-muted">{{ current?.message ?? t('common.irreversible') }}</p>
        <template #footer>
            <UiButton variant="secondary" @click="settle(false)">{{ t('common.cancel') }}</UiButton>
            <UiButton :variant="current?.danger ? 'danger' : 'primary'" @click="settle(true)">
                {{ current?.confirmLabel ?? t('common.confirm') }}
            </UiButton>
        </template>
    </UiDialog>
</template>
