<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { ensureCsrfCookie, http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const ui = useUiStore();
const form = reactive({ token: String(route.query.token ?? ''), password: '', password_confirmation: '' });
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        await ensureCsrfCookie();
        const { data } = await http.post('/auth/reset-password', form);
        ui.notify(data.message);
        router.replace({ name: 'login' });
    } catch (error) {
        errors.value = toApiError(error).errors;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ t('auth.resetTitle') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ t('auth.passwordRules') }}</p>
    <p v-if="errors.token" class="mt-6 rounded-lg bg-danger/10 p-3 text-sm text-danger">{{ errors.token[0] }}</p>
    <form class="mt-8 space-y-5" @submit.prevent="submit">
        <UiInput v-model="form.password" type="password" :label="t('auth.newPassword')" autocomplete="new-password" required :error="errors.password" />
        <UiInput v-model="form.password_confirmation" type="password" :label="t('auth.confirmPassword')" autocomplete="new-password" required />
        <UiButton type="submit" block :loading="busy">{{ t('auth.resetButton') }}</UiButton>
    </form>
</template>
