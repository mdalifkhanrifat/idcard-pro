<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import OnceSecret from './OnceSecret.vue';

defineProps<{ name: string; secret: string }>();
const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();
</script>

<template>
    <UiDialog v-model:open="open" :title="t('webhooks.secret.title', { name })" full-on-phone>
        <div class="space-y-3" data-webhook-secret>
            <OnceSecret :value="secret" :warning="t('webhooks.secret.onlyOnce')" />
            <p class="text-sm text-muted">{{ t('webhooks.secret.howTo') }}</p>
        </div>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.close') }}</UiButton>
        </template>
    </UiDialog>
</template>
