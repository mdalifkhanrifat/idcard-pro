<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { KeyRound } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { useConfirm } from '@/composables/useConfirm';
import { formatDate, formatNumber, formatRelative } from '@/lib/format';
import { toApiError } from '@/lib/http';
import { createToken, fetchTokens, revokeToken, type ApiToken, type Integration, type IntegrationOptions } from '@/lib/integrations/api';
import { endsSoon } from '@/lib/integrations/tokens';
import { useUiStore } from '@/stores/ui';
import OnceSecret from './OnceSecret.vue';

const props = defineProps<{ account: Integration | null; options: IntegrationOptions | undefined; canMake: boolean }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ changed: [] }>();
const { t } = useI18n();
const ui = useUiStore();
const { confirm } = useConfirm();

const tokens = ref<ApiToken[]>([]);
const loading = ref(false);
const failed = ref(false);
const busy = ref(false);
const form = reactive({ name: '', days: 365 as number | string });
const errors = ref<Record<string, string[]>>({});
const plain = ref<string | null>(null);
const apiUrl = `${window.location.origin}/api/v1`;
const example = computed(() => `curl -H "Authorization: Bearer ${plain.value ?? '<token>'}" -H "Accept: application/json" ${apiUrl}/cardholders`);

async function load(): Promise<void> {
    if (props.account === null) {
        return;
    }
    loading.value = true;
    failed.value = false;
    try {
        tokens.value = await fetchTokens(props.account.id);
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(open, (value) => {
    plain.value = null;
    if (value) {
        errors.value = {};
        Object.assign(form, { name: '', days: props.options?.token_days ?? 365 });
        void load();
    }
});

async function make(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        const token = await createToken(props.account!.id, form.name, form.days);
        plain.value = token.plain_text_token;
        form.name = '';
        emit('changed');
        await load();
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.errors;
        if (!Object.keys(apiError.errors).length) {
            ui.notify(apiError.message, 'error');
        }
    } finally {
        busy.value = false;
    }
}

async function revoke(token: ApiToken): Promise<void> {
    if (!(await confirm({ title: t('common.areYouSure'), message: t('integrations.tokens.revokeConfirm', { name: token.name }), confirmLabel: t('integrations.tokens.revoke'), danger: true }))) {
        return;
    }
    try {
        await revokeToken(props.account!.id, token.id);
        ui.notify(t('integrations.tokens.revoked'));
        emit('changed');
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}
</script>

<template>
    <UiDialog v-model:open="open" :title="t('integrations.tokens.title', { name: account?.name ?? '' })" size="lg" full-on-phone>
        <div class="space-y-5" data-integration-tokens>
            <OnceSecret v-if="plain" :value="plain" :warning="t('integrations.tokens.onlyOnce')" />

            <form v-if="canMake" class="grid gap-3 sm:grid-cols-[1fr_9rem_auto] sm:items-start" data-token-form @submit.prevent="make">
                <UiInput v-model="form.name" :label="t('integrations.tokens.name')" :hint="t('integrations.tokens.nameHint')" required :error="errors.name" />
                <UiInput v-model="form.days" type="number" min="1" :max="String(options?.max_token_days ?? '')" :label="t('integrations.tokens.days')" :hint="t('integrations.tokens.daysHint', { days: formatNumber(options?.max_token_days ?? 0) })" required :error="errors.expires_in_days" />
                <UiButton type="submit" class="sm:mt-7" :loading="busy"><KeyRound class="size-4" />{{ t('integrations.tokens.make') }}</UiButton>
            </form>
            <p v-else class="text-sm text-muted" data-token-beyond>{{ t('integrations.tokens.beyondReach') }}</p>

            <section>
                <h3 class="mb-2 text-sm font-medium text-fg">{{ t('integrations.tokens.list') }}</h3>
                <p v-if="failed" class="text-sm text-danger">{{ t('integrations.tokens.loadFailed') }} <button type="button" class="underline" @click="load">{{ t('integrations.tokens.retry') }}</button></p>
                <p v-else-if="!tokens.length && !loading" class="text-sm text-muted" data-token-none>{{ t('integrations.tokens.none') }}</p>
                <ul v-else class="divide-y divide-line rounded-lg border border-line" :class="loading && 'opacity-50'">
                    <li v-for="token in tokens" :key="token.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm" data-token-row>
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 break-words text-fg" dir="auto">
                                {{ token.name }}
                                <UiBadge v-if="token.expired" tone="danger">{{ t('integrations.tokens.expired') }}</UiBadge>
                                <UiBadge v-else-if="endsSoon(token.expires_at)" tone="warning" data-token-soon>{{ t('integrations.tokens.endsSoon') }}</UiBadge>
                            </p>
                            <p class="text-xs text-muted">
                                {{ t('integrations.tokens.ends', { date: formatDate(token.expires_at) }) }} ·
                                {{ token.last_used_at ? t('integrations.tokens.used', { when: formatRelative(token.last_used_at) }) : t('integrations.tokens.neverUsed') }}
                            </p>
                        </div>
                        <UiButton variant="ghost" data-token-revoke @click="revoke(token)">{{ t('integrations.tokens.revoke') }}</UiButton>
                    </li>
                </ul>
            </section>

            <details class="rounded-lg border border-line p-3 text-sm" data-token-howto>
                <summary class="cursor-pointer font-medium text-fg">{{ t('integrations.tokens.howTo') }}</summary>
                <p class="mt-2 text-muted">{{ t('integrations.tokens.howToText') }}</p>
                <code class="mt-2 block break-all rounded-md bg-surface-2 p-3 font-mono text-xs text-fg" dir="ltr">{{ example }}</code>
            </details>
        </div>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.close') }}</UiButton>
        </template>
    </UiDialog>
</template>
