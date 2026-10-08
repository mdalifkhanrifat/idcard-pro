<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ShieldCheck } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const { t } = useI18n();

function savedOrganization(): string {
    try {
        return localStorage.getItem('organization') ?? '';
    } catch {
        return '';
    }
}

const form = reactive({ organization: savedOrganization(), login: '', password: '', remember: false });
const challenge = reactive({ code: '', recovery_code: '', useRecovery: false });
const step = ref<'credentials' | 'two-factor'>('credentials');
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

async function finish(): Promise<void> {
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/';
    await router.replace(redirect);
}

async function submitCredentials(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        const { twoFactor } = await auth.login({ ...form, organization: form.organization.trim() || undefined });
        try {
            localStorage.setItem('organization', form.organization.trim());
        } catch {
            /* storage unavailable */
        }
        if (twoFactor) {
            step.value = 'two-factor';
        } else {
            await finish();
        }
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.status === 429 ? { login: [apiError.message] } : apiError.errors;
        form.password = '';
    } finally {
        busy.value = false;
    }
}

async function submitChallenge(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        await auth.completeTwoFactor(challenge.useRecovery ? { recovery_code: challenge.recovery_code } : { code: challenge.code });
        await finish();
    } catch (error) {
        errors.value = toApiError(error).errors;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div v-if="step === 'credentials'">
        <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ t('auth.signInTitle') }}</h1>
        <form class="mt-8 space-y-5" novalidate @submit.prevent="submitCredentials">
            <UiInput v-model="form.organization" :label="t('auth.organization')" :hint="t('auth.organizationHint')" autocomplete="organization" :error="errors.organization" />
            <UiInput v-model="form.login" :label="t('auth.login')" autocomplete="username" required :error="errors.login" autofocus />
            <div>
                <UiInput v-model="form.password" type="password" :label="t('auth.password')" autocomplete="current-password" required :error="errors.password" />
                <div class="mt-2 text-right">
                    <RouterLink :to="{ name: 'forgot-password' }" class="text-sm font-medium text-brand-ink hover:underline">{{ t('auth.forgot') }}</RouterLink>
                </div>
            </div>
            <UiCheckbox v-model="form.remember" :label="t('auth.remember')" />
            <UiButton type="submit" block :loading="busy">{{ t('auth.signIn') }}</UiButton>
        </form>
    </div>

    <div v-else>
        <div class="mb-6 flex size-12 items-center justify-center rounded-xl bg-brand/10 text-brand-ink"><ShieldCheck class="size-6" /></div>
        <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ t('auth.twoFactorTitle') }}</h1>
        <p class="mt-2 text-sm text-muted">{{ challenge.useRecovery ? t('account.recoveryHint') : t('auth.twoFactorHint') }}</p>
        <form class="mt-8 space-y-5" @submit.prevent="submitChallenge">
            <UiInput
                v-if="!challenge.useRecovery"
                v-model="challenge.code"
                :label="t('auth.code')"
                inputmode="numeric"
                autocomplete="one-time-code"
                :maxlength="6"
                autofocus
                :error="errors.code"
            />
            <UiInput v-else v-model="challenge.recovery_code" :label="t('auth.recoveryCode')" autocomplete="off" :error="errors.code" />
            <UiButton type="submit" block :loading="busy">{{ t('auth.verify') }}</UiButton>
            <div class="flex justify-between text-sm">
                <button type="button" class="font-medium text-brand-ink hover:underline" @click="challenge.useRecovery = !challenge.useRecovery">
                    {{ challenge.useRecovery ? t('auth.useCode') : t('auth.useRecovery') }}
                </button>
                <button type="button" class="text-muted hover:text-fg" @click="step = 'credentials'">{{ t('auth.back') }}</button>
            </div>
        </form>
    </div>
</template>
