<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { MailCheck } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { ensureCsrfCookie, http, toApiError } from '@/lib/http';

const { t } = useI18n();
function savedOrganization(): string {
    try {
        return localStorage.getItem('organization') ?? '';
    } catch {
        return '';
    }
}

const form = reactive({ organization: savedOrganization(), email: '' });
const errors = ref<Record<string, string[]>>({});
const sent = ref<string | null>(null);
const busy = ref(false);

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        await ensureCsrfCookie();
        const { data } = await http.post('/auth/forgot-password', { ...form, organization: form.organization.trim() || null });
        sent.value = data.message;
    } catch (error) {
        errors.value = toApiError(error).errors;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ t('auth.forgotTitle') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ t('auth.forgotHint') }}</p>

    <div v-if="sent" class="mt-8 flex gap-3 rounded-xl border border-success/30 bg-success/10 p-4 text-sm text-fg">
        <MailCheck class="size-5 shrink-0 text-success" />{{ sent }}
    </div>
    <form v-else class="mt-8 space-y-5" @submit.prevent="submit">
        <UiInput v-model="form.organization" :label="t('auth.organization')" :error="errors.organization" />
        <UiInput v-model="form.email" type="email" :label="t('auth.email')" required autocomplete="email" :error="errors.email" />
        <UiButton type="submit" block :loading="busy">{{ t('auth.sendLink') }}</UiButton>
    </form>
    <RouterLink :to="{ name: 'login' }" class="mt-6 inline-block text-sm font-medium text-brand hover:underline">{{ t('auth.back') }}</RouterLink>
</template>
