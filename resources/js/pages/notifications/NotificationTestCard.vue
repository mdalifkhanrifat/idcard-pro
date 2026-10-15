<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckCircle2, Clock, Send, XCircle } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { toApiError } from '@/lib/http';
import { fetchMessage, sendTestMessage, type NotificationMessage, type NotificationOptions } from '@/lib/notifications/api';
import { useAuthStore } from '@/stores/auth';

/**
 * A test message to check that a channel gets through — before turning it on, too: any channel of the registry,
 * to one's own address or one typed; then its progress until it is sent or fails. Fixed words in the saved
 * languages; sent whatever the settings.
 */
const props = defineProps<{ options: NotificationOptions; organizationChannels: string[] }>();
const { t } = useI18n();
const auth = useAuthStore();

/** How long to watch a message that is still waiting (the queue may be stopped). */
const POLL_MS = 2000;
const POLL_LIMIT_MS = 30_000;

const channel = ref(props.organizationChannels[0] ?? props.options.channels[0] ?? '');
const kind = computed(() => props.options.channel_addresses[channel.value] ?? 'email');
const ownAddress = computed(() => (kind.value === 'phone' ? auth.user?.phone : auth.user?.email) ?? '');
const address = ref('');
// One's own address for the channel's kind, until something else is typed
watch(kind, () => (address.value = ownAddress.value), { immediate: true });

const channelOptions = computed(() =>
    props.options.channels.map((key) => ({
        value: key,
        label: props.organizationChannels.includes(key) ? (props.options.labels.channels[key] ?? key) : t('notifications.test.offChannel', { channel: props.options.labels.channels[key] ?? key }),
    })),
);

const sending = ref(false);
const errors = ref<Record<string, string[]>>({});
const failure = ref('');
const message = ref<NotificationMessage | null>(null);
/** The message is still waiting after the time it should take: the queue may not be running. */
const stalled = ref(false);
/** May send but not see the log: the progress cannot be followed. */
const unseen = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

function stop(): void {
    clearTimeout(timer);
    timer = undefined;
}
onBeforeUnmount(stop);

async function follow(id: string, startedAt: number): Promise<void> {
    try {
        message.value = await fetchMessage(id);
    } catch (error) {
        if (toApiError(error).status === 403) {
            unseen.value = true;

            return;
        }
    }
    if (message.value?.status.key !== 'queued') {
        return;
    }
    if (Date.now() - startedAt >= POLL_LIMIT_MS) {
        stalled.value = true;

        return;
    }
    timer = setTimeout(() => follow(id, startedAt), POLL_MS);
}

async function send(): Promise<void> {
    stop();
    sending.value = true;
    errors.value = {};
    failure.value = '';
    message.value = null;
    stalled.value = false;
    unseen.value = false;
    try {
        const sent = await sendTestMessage(channel.value, address.value.trim());
        message.value = sent;
        if (sent.status.key === 'queued') {
            timer = setTimeout(() => follow(sent.id, Date.now()), POLL_MS);
        }
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.errors;
        failure.value = apiError.status === 429 ? t('notifications.test.tooMany') : Object.keys(apiError.errors).length ? '' : apiError.message;
    } finally {
        sending.value = false;
    }
}

const status = computed(() => message.value?.status.key);

// Another channel: the last one's result no longer applies
watch(channel, () => {
    stop();
    message.value = null;
    errors.value = {};
    failure.value = '';
    stalled.value = false;
    unseen.value = false;
});
</script>

<template>
    <UiCard :title="t('notifications.test.title')" :description="t('notifications.test.description')" data-test-card>
        <form class="grid gap-4 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] sm:items-start" @submit.prevent="send">
            <UiSelect v-model="channel" :label="t('notifications.test.channel')" :options="channelOptions" :error="errors.channel" data-test-channel />
            <UiInput
                v-model="address"
                :type="kind === 'phone' ? 'tel' : 'email'"
                :inputmode="kind === 'phone' ? 'tel' : 'email'"
                :label="kind === 'phone' ? t('notifications.test.phone') : t('notifications.test.email')"
                :placeholder="kind === 'phone' ? '+8801XXXXXXXXX' : 'name@example.com'"
                :error="errors.address"
                :maxlength="255"
                data-test-address
            />
            <div class="sm:pt-6">
                <UiButton type="submit" :loading="sending" class="w-full sm:w-auto" data-test-send><Send class="size-4" />{{ t('notifications.test.send') }}</UiButton>
            </div>
        </form>
        <p class="mt-2 text-xs text-muted">{{ t('notifications.test.hint') }}</p>
        <p v-if="failure" class="mt-3 text-sm text-danger" data-test-failure>{{ failure }}</p>

        <div v-if="message" class="mt-4 rounded-lg border border-line p-3 text-sm" :data-test-status="status">
            <p v-if="status === 'queued'" class="flex items-start gap-2 text-fg"><Clock class="mt-0.5 size-4 shrink-0 animate-pulse text-muted" />{{ t('notifications.test.queued', { address: message.address }) }}</p>
            <p v-else-if="status === 'sent'" class="flex items-start gap-2 text-success"><CheckCircle2 class="mt-0.5 size-4 shrink-0" />{{ t('notifications.test.sent', { address: message.address }) }}</p>
            <div v-else class="flex items-start gap-2 text-danger">
                <XCircle class="mt-0.5 size-4 shrink-0" />
                <div class="min-w-0">
                    <p>{{ message.status.name }}<template v-if="message.reason">: {{ message.reason.name }}</template></p>
                    <p v-if="message.error" class="mt-1 text-xs break-words">{{ message.error }}</p>
                </div>
            </div>
            <p v-if="stalled" class="mt-2 text-xs text-warning" data-test-stalled>{{ t('notifications.test.stalled') }}</p>
            <p v-if="unseen" class="mt-2 text-xs text-muted">{{ t('notifications.test.unseen') }}</p>
        </div>
    </UiCard>
</template>
