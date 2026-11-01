<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, RefreshCw, Send } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import { formatDateTime, formatNumber } from '@/lib/format';
import { toApiError } from '@/lib/http';
import { deliveryTone, fetchDelivery, prettyPayload, resendDelivery, type WebhookDeliveryDetail } from '@/lib/integrations/webhooks';
import { useUiStore } from '@/stores/ui';

const props = defineProps<{ webhookId: string; deliveryId: string }>();
const emit = defineEmits<{ back: []; open: [id: string]; changed: [] }>();
const { t } = useI18n();
const ui = useUiStore();

const delivery = ref<WebhookDeliveryDetail | null>(null);
const loading = ref(false);
const failed = ref(false);
const sending = ref(false);
const resend = computed(() => delivery.value?.actions.resend ?? null);
const payload = computed(() => prettyPayload(delivery.value?.payload ?? null));

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    try {
        delivery.value = await fetchDelivery(props.webhookId, props.deliveryId);
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch(() => props.deliveryId, load);

async function sendAgain(): Promise<void> {
    sending.value = true;
    try {
        const sent = await resendDelivery(props.webhookId, props.deliveryId);
        ui.notify(t('webhooks.log.resent'));
        emit('changed');
        emit('open', sent.id);
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
        await load();
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <div class="space-y-4" data-delivery-detail>
        <div class="flex flex-wrap items-center gap-2">
            <UiButton variant="ghost" data-delivery-back @click="emit('back')"><ArrowLeft class="size-4 rtl:rotate-180" />{{ t('webhooks.log.back') }}</UiButton>
            <UiButton variant="ghost" class="ml-auto" :loading="loading" data-delivery-refresh @click="load"><RefreshCw class="size-4" />{{ t('webhooks.log.refresh') }}</UiButton>
        </div>

        <p v-if="failed" class="text-sm text-danger" data-delivery-failed>{{ t('webhooks.log.loadFailed') }} <button type="button" class="underline" @click="load">{{ t('webhooks.log.retry') }}</button></p>
        <template v-else-if="delivery">
            <div class="flex flex-wrap items-center gap-2">
                <p class="font-medium break-words text-fg">{{ delivery.event.name }}</p>
                <UiBadge :tone="deliveryTone(delivery.status.key)" data-delivery-status>{{ delivery.status.name }}</UiBadge>
            </div>

            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted">{{ t('webhooks.log.createdAt') }}</dt>
                    <dd class="text-fg">{{ formatDateTime(delivery.created_at) }}</dd>
                </div>
                <div v-if="delivery.delivered_at">
                    <dt class="text-xs text-muted">{{ t('webhooks.log.deliveredAt') }}</dt>
                    <dd class="text-fg">{{ formatDateTime(delivery.delivered_at) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">{{ t('webhooks.log.attemptsLabel') }}</dt>
                    <dd class="text-fg">{{ formatNumber(delivery.attempts) }}</dd>
                </div>
                <div v-if="delivery.response_code !== null">
                    <dt class="text-xs text-muted">{{ t('webhooks.log.responseCode') }}</dt>
                    <dd class="text-fg">{{ formatNumber(delivery.response_code) }}</dd>
                </div>
                <div v-if="delivery.failure" class="sm:col-span-2">
                    <dt class="text-xs text-muted">{{ t('webhooks.log.failure') }}</dt>
                    <dd class="break-words text-danger" data-delivery-failure>{{ delivery.failure.name }}</dd>
                </div>
            </dl>

            <div v-if="delivery.resend_of || delivery.resent_as" class="space-y-1 text-sm">
                <button v-if="delivery.resend_of" type="button" class="block text-brand-ink hover:underline" data-delivery-resend-of @click="emit('open', delivery.resend_of)">{{ t('webhooks.log.resendOf') }}</button>
                <button v-if="delivery.resent_as" type="button" class="block text-brand-ink hover:underline" data-delivery-resent-as @click="emit('open', delivery.resent_as)">{{ t('webhooks.log.resentAs') }}</button>
            </div>

            <div v-if="resend" class="rounded-lg border border-line p-3">
                <UiButton :disabled="!resend.allowed" :loading="sending" data-delivery-resend @click="sendAgain"><Send class="size-4" />{{ t('webhooks.log.resend') }}</UiButton>
                <p class="mt-2 text-xs text-muted" data-delivery-resend-note>{{ resend.reason ?? t('webhooks.log.resendHint') }}</p>
            </div>

            <section>
                <h3 class="mb-2 text-sm font-medium text-fg">{{ t('webhooks.log.payload') }}</h3>
                <p v-if="delivery.payload_hidden" class="rounded-lg bg-surface-2 p-3 text-sm text-muted" data-delivery-hidden>{{ t('webhooks.log.payloadHidden') }}</p>
                <pre v-else class="max-h-80 overflow-auto rounded-lg bg-surface-2 p-3 font-mono text-xs text-fg" dir="ltr" tabindex="0" data-delivery-payload>{{ payload }}</pre>
            </section>
        </template>
    </div>
</template>
