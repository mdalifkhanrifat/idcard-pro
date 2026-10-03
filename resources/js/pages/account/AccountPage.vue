<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useClipboard } from '@vueuse/core';
import { Copy, KeyRound, ShieldCheck, ShieldOff, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useConfirm } from '@/composables/useConfirm';
import { http, toApiError } from '@/lib/http';
import { formatRelative } from '@/lib/format';
import { setLocale } from '@/lib/i18n';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();
const { copy, copied } = useClipboard();

/* Profile */
const profile = reactive({ name: auth.user?.name ?? '', phone: auth.user?.phone ?? '', locale: auth.user?.locale ?? 'en' });
const profileErrors = ref<Record<string, string[]>>({});
const profileBusy = ref(false);
async function saveProfile(): Promise<void> {
    profileBusy.value = true;
    profileErrors.value = {};
    try {
        await http.put('/auth/me', profile);
        setLocale(profile.locale);
        await auth.fetchSession();
        ui.notify(t('common.saved'));
    } catch (error) {
        profileErrors.value = toApiError(error).errors;
    } finally {
        profileBusy.value = false;
    }
}

/* Password */
const password = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordErrors = ref<Record<string, string[]>>({});
const passwordBusy = ref(false);
async function changePassword(): Promise<void> {
    passwordBusy.value = true;
    passwordErrors.value = {};
    try {
        const { data } = await http.put('/auth/password', password);
        Object.assign(password, { current_password: '', password: '', password_confirmation: '' });
        ui.notify(data.message);
    } catch (error) {
        passwordErrors.value = toApiError(error).errors;
    } finally {
        passwordBusy.value = false;
    }
}

/* Two-factor */
const tf = reactive({ open: false, step: 'password' as 'password' | 'scan' | 'codes', password: '', code: '', secret: '', qr: '', codes: [] as string[], busy: false, errors: {} as Record<string, string[]>, disabling: false });
function startTwoFactor(disabling: boolean): void {
    Object.assign(tf, { open: true, step: 'password', password: '', code: '', errors: {}, disabling });
}
async function twoFactorPassword(): Promise<void> {
    tf.busy = true;
    tf.errors = {};
    try {
        if (tf.disabling) {
            await http.delete('/auth/two-factor', { data: { password: tf.password } });
            tf.open = false;
            await auth.fetchSession();
            ui.notify(t('common.saved'));
        } else {
            const { data } = await http.post('/auth/two-factor', { password: tf.password });
            Object.assign(tf, { secret: data.data.secret, qr: data.data.qr_svg, step: 'scan' });
        }
    } catch (error) {
        tf.errors = toApiError(error).errors;
    } finally {
        tf.busy = false;
    }
}
async function twoFactorConfirm(): Promise<void> {
    tf.busy = true;
    tf.errors = {};
    try {
        const { data } = await http.post('/auth/two-factor/confirm', { code: tf.code });
        Object.assign(tf, { codes: data.data.recovery_codes, step: 'codes' });
        await auth.fetchSession();
    } catch (error) {
        tf.errors = toApiError(error).errors;
    } finally {
        tf.busy = false;
    }
}

/* API tokens */
interface Token { id: number; name: string; last_used_at: string | null; expires_at: string | null; created_at: string }
const tokens = ref<Token[]>([]);
const newToken = reactive({ name: '', expires_in_days: '90' as string, plain: '', errors: {} as Record<string, string[]> });
async function loadTokens(): Promise<void> {
    tokens.value = (await http.get('/auth/tokens')).data.data;
}
async function createToken(): Promise<void> {
    newToken.errors = {};
    try {
        const { data } = await http.post('/auth/tokens', { name: newToken.name, expires_in_days: newToken.expires_in_days ? Number(newToken.expires_in_days) : null });
        newToken.plain = data.data.plain_text_token;
        newToken.name = '';
        await loadTokens();
    } catch (error) {
        newToken.errors = toApiError(error).errors;
    }
}
async function revoke(token: Token): Promise<void> {
    if (await confirm({ title: `${t('account.revoke')} “${token.name}”?`, danger: true, confirmLabel: t('account.revoke') })) {
        await http.delete(`/auth/tokens/${token.id}`);
        await loadTokens();
    }
}
onMounted(loadTokens);
</script>

<template>
    <div>
        <UiPageHeader :title="t('account.title')" />
        <div class="grid gap-6 lg:grid-cols-2">
            <UiCard :title="t('account.profile')">
                <form class="space-y-4" @submit.prevent="saveProfile">
                    <UiInput v-model="profile.name" :label="t('users.name')" required :error="profileErrors.name" />
                    <UiInput :model-value="auth.user?.email" :label="t('users.email')" disabled />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiInput v-model="profile.phone" :label="t('users.phone')" :error="profileErrors.phone" />
                        <UiSelect v-model="profile.locale" :label="t('common.language')" :options="[{ value: 'en', label: 'English' }, { value: 'bn', label: 'বাংলা' }]" />
                    </div>
                    <UiButton type="submit" :loading="profileBusy">{{ t('common.save') }}</UiButton>
                </form>
            </UiCard>

            <UiCard :title="t('account.changePassword')" :description="t('auth.passwordRules')">
                <form class="space-y-4" @submit.prevent="changePassword">
                    <UiInput v-model="password.current_password" type="password" :label="t('account.currentPassword')" autocomplete="current-password" required :error="passwordErrors.current_password" />
                    <UiInput v-model="password.password" type="password" :label="t('auth.newPassword')" autocomplete="new-password" required :error="passwordErrors.password" />
                    <UiInput v-model="password.password_confirmation" type="password" :label="t('auth.confirmPassword')" autocomplete="new-password" required />
                    <UiButton type="submit" :loading="passwordBusy">{{ t('account.changePassword') }}</UiButton>
                </form>
            </UiCard>

            <UiCard :title="t('account.twoFactor')">
                <div class="flex items-start gap-4">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl" :class="auth.user?.two_factor_enabled ? 'bg-success/12 text-success' : 'bg-surface-2 text-muted'">
                        <component :is="auth.user?.two_factor_enabled ? ShieldCheck : ShieldOff" class="size-5" />
                    </span>
                    <div class="flex-1">
                        <p class="text-sm text-fg">{{ auth.user?.two_factor_enabled ? t('account.twoFactorOn') : t('account.twoFactorOff') }}</p>
                        <UiButton class="mt-4" :variant="auth.user?.two_factor_enabled ? 'secondary' : 'primary'" @click="startTwoFactor(!!auth.user?.two_factor_enabled)">
                            {{ auth.user?.two_factor_enabled ? t('account.disable') : t('account.enable') }}
                        </UiButton>
                    </div>
                </div>
            </UiCard>

            <UiCard :title="t('account.apiTokens')" :description="t('account.apiTokensHint')">
                <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="createToken">
                    <UiInput v-model="newToken.name" :label="t('account.tokenName')" class="flex-1" :error="newToken.errors.name" />
                    <UiInput v-model="newToken.expires_in_days" :label="t('account.expiresInDays')" inputmode="numeric" class="sm:w-32" :error="newToken.errors.expires_in_days" />
                    <UiButton type="submit"><KeyRound class="size-4" />{{ t('account.createToken') }}</UiButton>
                </form>
                <div v-if="newToken.plain" class="mt-4 rounded-lg border border-warning/40 bg-warning/10 p-3">
                    <p class="mb-2 text-xs text-warning">{{ t('account.tokenCreated') }}</p>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 truncate rounded bg-surface px-2 py-1.5 font-mono text-xs">{{ newToken.plain }}</code>
                        <UiButton size="sm" variant="secondary" @click="copy(newToken.plain)"><Copy class="size-3.5" />{{ copied ? t('common.copied') : t('common.copy') }}</UiButton>
                    </div>
                </div>
                <ul class="mt-4 divide-y divide-line">
                    <li v-for="token in tokens" :key="token.id" class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <div>
                            <p class="font-medium text-fg">{{ token.name }}</p>
                            <p class="text-xs text-muted">{{ t('account.lastUsed') }}: {{ token.last_used_at ? formatRelative(token.last_used_at) : t('common.never') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <UiBadge v-if="token.expires_at">{{ formatRelative(token.expires_at) }}</UiBadge>
                            <button class="rounded-md p-1.5 text-muted hover:bg-danger/10 hover:text-danger" :aria-label="t('account.revoke')" @click="revoke(token)"><Trash2 class="size-4" /></button>
                        </div>
                    </li>
                </ul>
            </UiCard>
        </div>

        <UiDialog v-model:open="tf.open" :title="t('account.twoFactor')">
            <form v-if="tf.step === 'password'" id="tf-form" class="space-y-4" @submit.prevent="twoFactorPassword">
                <p class="text-sm text-muted">{{ t('account.confirmPasswordHint') }}</p>
                <UiInput v-model="tf.password" type="password" :label="t('auth.password')" autocomplete="current-password" required autofocus :error="tf.errors.password" />
            </form>
            <form v-else-if="tf.step === 'scan'" id="tf-form" class="space-y-4" @submit.prevent="twoFactorConfirm">
                <p class="text-sm text-muted">{{ t('account.scanQr') }}</p>
                <div class="mx-auto w-fit rounded-xl bg-white p-3" v-html="tf.qr" />
                <div class="text-center">
                    <p class="text-xs text-muted">{{ t('account.manualKey') }}</p>
                    <code class="mt-1 inline-block rounded bg-surface-2 px-2 py-1 font-mono text-xs tracking-wider break-all">{{ tf.secret }}</code>
                </div>
                <UiInput v-model="tf.code" :label="t('auth.code')" inputmode="numeric" autocomplete="one-time-code" :maxlength="6" required :error="tf.errors.code" />
            </form>
            <div v-else class="space-y-4">
                <p class="text-sm text-muted">{{ t('account.recoveryHint') }}</p>
                <div class="grid grid-cols-2 gap-2 rounded-lg bg-surface-2 p-4 font-mono text-sm">
                    <span v-for="code in tf.codes" :key="code">{{ code }}</span>
                </div>
                <UiButton variant="secondary" @click="copy(tf.codes.join('\n'))"><Copy class="size-4" />{{ copied ? t('common.copied') : t('common.copy') }}</UiButton>
            </div>
            <template #footer>
                <UiButton v-if="tf.step !== 'codes'" variant="secondary" @click="tf.open = false">{{ t('common.cancel') }}</UiButton>
                <UiButton v-if="tf.step !== 'codes'" type="submit" form="tf-form" :loading="tf.busy" :variant="tf.disabling ? 'danger' : 'primary'">
                    {{ tf.disabling ? t('account.disable') : t('common.confirm') }}
                </UiButton>
                <UiButton v-else @click="tf.open = false">{{ t('common.close') }}</UiButton>
            </template>
        </UiDialog>
    </div>
</template>
