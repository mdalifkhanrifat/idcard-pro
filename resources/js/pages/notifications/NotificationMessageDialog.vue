<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { RotateCw } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import { formatDateTime, formatNumber } from '@/lib/format';
import { toApiError } from '@/lib/http';
import { fetchMessage, resendMessage, type NotificationMessage } from '@/lib/notifications/api';
import { cardholderLink, statusTone } from '@/pages/notifications/messages';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';

/**
 * One logged message in full: to whom and about what, the words as sent, how it went (reason, error, attempts,
 * driver), who sent it by hand, and the messages linked by sending again; a failed or skipped one can be sent
 * again from here.
 */
const props = defineProps<{ messageId: string; languageNames: Record<string, string> }>();
const emit = defineEmits<{ resent: [] }>();
const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();

const message = ref<NotificationMessage | null>(null);
const loading = ref(false);
const resending = ref(false);
const resendError = ref('');
const timeZone = computed(() => auth.tenant?.timezone);

async function show(id: string): Promise<void> {
    loading.value = true;
    resendError.value = '';
    try {
        message.value = await fetchMessage(id);
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
        open.value = false;
    } finally {
        loading.value = false;
    }
}
watch(() => props.messageId, show, { immediate: true });

const canResend = computed(() => auth.can('notifications.send') && !!message.value && ['failed', 'skipped'].includes(message.value.status.key) && !message.value.resent_as);

async function resend(): Promise<void> {
    if (!message.value) {
        return;
    }
    resending.value = true;
    resendError.value = '';
    try {
        const sent = await resendMessage(message.value.id);
        ui.notify(t('notifications.messages.resent'));
        emit('resent');
        // On to the new message, to follow it
        await show(sent.id);
    } catch (error) {
        const apiError = toApiError(error);
        resendError.value = apiError.errors.message?.[0] ?? (apiError.status === 429 ? t('notifications.test.tooMany') : apiError.message);
    } finally {
        resending.value = false;
    }
}

const rows = computed(() => {
    const m = message.value;
    if (!m) {
        return [];
    }

    return [
        { key: 'event', label: t('notifications.messages.event'), value: m.event.name },
        { key: 'channel', label: t('notifications.messages.channel'), value: m.channel.name },
        { key: 'address', label: t('notifications.messages.address'), value: m.address ?? '—' },
        { key: 'languages', label: t('notifications.messages.languages'), value: m.languages.map((language) => props.languageNames[language] ?? language).join(' → ') },
        { key: 'created', label: t('notifications.messages.created'), value: formatDateTime(m.created_at, timeZone.value) },
        { key: 'sent', label: m.failed_at ? t('notifications.messages.failedAt') : t('notifications.messages.sentAt'), value: m.sent_at || m.failed_at ? formatDateTime(m.sent_at ?? m.failed_at, timeZone.value) : '—' },
        { key: 'attempts', label: t('notifications.messages.attempts'), value: formatNumber(m.attempts) },
        { key: 'driver', label: t('notifications.messages.driver'), value: [m.driver, m.provider_id].filter(Boolean).join(' · ') || '—' },
        { key: 'sent_by', label: t('notifications.messages.sentBy'), value: m.sent_by?.name ?? t('notifications.messages.system') },
    ];
});
</script>

<template>
    <UiDialog v-model:open="open" :title="t('notifications.messages.details')" size="lg" full-on-phone>
        <p v-if="loading && !message" class="py-8 text-center text-sm text-muted">{{ t('common.loading') }}</p>
        <div v-else-if="message" class="space-y-5 text-sm" :data-message="message.id">
            <div class="flex flex-wrap items-start gap-2">
                <UiBadge :tone="statusTone(message.status.key)" data-message-status>{{ message.status.name }}</UiBadge>
                <span v-if="message.reason" class="text-muted">{{ message.reason.name }}</span>
            </div>
            <p v-if="message.error" class="rounded-lg bg-danger/8 px-3 py-2 text-xs break-words text-danger" data-message-error>{{ message.error }}</p>

            <div>
                <p class="text-xs text-muted">{{ t('notifications.messages.recipient') }}</p>
                <p class="mt-0.5 text-fg">
                    <RouterLink v-if="message.recipient && cardholderLink(message.recipient)" :to="cardholderLink(message.recipient)!" class="font-medium hover:underline">{{ message.recipient.name }}</RouterLink>
                    <span v-else-if="message.recipient" class="font-medium">{{ message.recipient.name }}</span>
                    <span v-else class="text-muted">{{ t('notifications.messages.recordGone') }}</span>
                    <span v-if="message.recipient" class="text-muted"> · {{ t(`notifications.messages.recipients.${message.recipient.type}`) }}</span>
                </p>
                <p v-if="message.card" class="mt-1 text-xs text-muted">{{ t('notifications.messages.card') }}: <span class="font-mono text-fg">{{ message.card.number }}</span></p>
            </div>

            <dl class="grid gap-3 sm:grid-cols-2">
                <div v-for="row in rows" :key="row.key" class="min-w-0">
                    <dt class="text-xs text-muted">{{ row.label }}</dt>
                    <dd class="mt-0.5 break-words text-fg">{{ row.value }}</dd>
                </div>
            </dl>

            <div class="rounded-xl border border-line">
                <p v-if="message.subject" class="border-b border-line px-3 py-2 font-semibold break-words text-fg" dir="auto" data-message-subject>{{ message.subject }}</p>
                <p class="px-3 py-2 break-words whitespace-pre-line text-fg" dir="auto" data-message-body>{{ message.body }}</p>
            </div>

            <div v-if="message.resend_of || message.resent_as" class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                <button v-if="message.resend_of" type="button" class="text-brand-ink hover:underline" data-open-resend-of @click="show(message.resend_of)">{{ t('notifications.messages.openOriginal') }}</button>
                <button v-if="message.resent_as" type="button" class="text-brand-ink hover:underline" data-open-resent-as @click="show(message.resent_as)">{{ t('notifications.messages.openResent') }}</button>
            </div>
            <p v-if="resendError" class="text-sm text-danger" data-resend-error>{{ resendError }}</p>
        </div>

        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.close') }}</UiButton>
            <UiButton v-if="canResend" :loading="resending" data-resend @click="resend"><RotateCw class="size-4" />{{ t('notifications.messages.resend') }}</UiButton>
        </template>
    </UiDialog>
</template>
