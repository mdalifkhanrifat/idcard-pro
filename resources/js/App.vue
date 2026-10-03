<script setup lang="ts">
import { watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import UiToasts from '@/components/ui/UiToasts.vue';
import UiConfirmHost from '@/components/ui/UiConfirmHost.vue';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const { t } = useI18n();
const auth = useAuthStore();

watchEffect(() => {
    const page = route.meta.title ? t(route.meta.title) : '';
    const org = auth.tenant?.name ?? t('app.name');
    document.title = page ? `${page} · ${org}` : org;
});
</script>

<template>
    <RouterView />
    <UiToasts />
    <UiConfirmHost />
</template>
