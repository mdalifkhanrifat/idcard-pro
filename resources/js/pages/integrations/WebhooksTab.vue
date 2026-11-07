<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { History, KeyRound, Pencil, Plus, Power, RotateCcw, Search, Send, Trash2 } from 'lucide-vue-next';
import ListPagination from '@/components/ListPagination.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import WebhookFormDialog from './WebhookFormDialog.vue';
import WebhookLogDialog from './WebhookLogDialog.vue';
import WebhookSecretDialog from './WebhookSecretDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useListQuery } from '@/composables/useListQuery';
import { useUrlOpen } from '@/composables/useUrlOpen';
import { formatNumber } from '@/lib/format';
import { toApiError } from '@/lib/http';
import { deleteWebhook, rotateWebhookSecret, testWebhook, turnedItselfOff, updateWebhook, type Webhook, type WebhookEventGroup, type WebhookWithSecret } from '@/lib/integrations/webhooks';
import { useUiStore } from '@/stores/ui';

const { t } = useI18n();
const ui = useUiStore();
const { confirm } = useConfirm();

const list = useListQuery<Webhook>('/webhooks', { filters: { search: '', enabled: '' }, sort: 'name', queryPrefix: 'webhook_' });
const { filters, rows, meta, loading } = list;

/** What can be chosen, grouped and named, and which groups this person may send somewhere: the server's word. */
const groups = computed(() => ((meta.value as { events?: WebhookEventGroup[] }).events ?? []));
/** Failures in a row at which a webhook turns itself off (the organization's setting), so a count says how near it is. */
const disableAfter = computed(() => (meta.value as { disable_after?: number }).disable_after ?? null);
const filtered = computed(() => Boolean(filters.search || filters.enabled));

const formOpen = ref(false);
const editing = ref<Webhook | null>(null);
const secretOpen = ref(false);
const shown = ref({ name: '', secret: '' });
const testing = ref<string | null>(null);
/** Whose log is open: in the URL with its filters and the delivery shown, so a reload or a link comes back here. */
const logOf = useUrlOpen('webhook_log');
const route = useRoute();
const router = useRouter();

onMounted(() => list.load());

function closeLog(): void {
    router.replace({ query: Object.fromEntries(Object.entries(route.query).filter(([key]) => !key.startsWith('webhook_log') && key !== 'webhook_delivery')) });
    list.load();
}

function openCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(webhook: Webhook): void {
    editing.value = webhook;
    formOpen.value = true;
}

function showSecret(webhook: WebhookWithSecret): void {
    shown.value = { name: webhook.name, secret: webhook.secret };
    secretOpen.value = true;
}

function created(webhook: WebhookWithSecret): void {
    ui.notify(t('common.saved'));
    list.load();
    showSecret(webhook);
}

/** Runs one action on a row: whatever refuses it is told, and the list shows how things stand now. */
async function act(work: () => Promise<void>): Promise<void> {
    try {
        await work();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
    list.load();
}

async function rotate(webhook: Webhook): Promise<void> {
    if (await confirm({ title: t('common.areYouSure'), message: t('webhooks.secret.rotateConfirm', { name: webhook.name }), confirmLabel: t('webhooks.secret.rotate'), danger: true })) {
        await act(async () => showSecret(await rotateWebhookSecret(webhook.id)));
    }
}

async function remove(webhook: Webhook): Promise<void> {
    if (await confirm({ title: t('common.areYouSure'), message: t('webhooks.deleteConfirm', { name: webhook.name }), confirmLabel: t('common.delete'), danger: true })) {
        await act(async () => {
            await deleteWebhook(webhook.id);
            ui.notify(t('common.deleted'));
        });
    }
}

async function turnOn(webhook: Webhook): Promise<void> {
    await act(async () => {
        await updateWebhook(webhook.id, { enabled: true });
        ui.notify(t('webhooks.turnedOn'));
    });
}

async function test(webhook: Webhook): Promise<void> {
    testing.value = webhook.id;
    await act(async () => {
        const result = await testWebhook(webhook.id);
        if (result.status.key === 'delivered') {
            ui.notify(t('webhooks.testDelivered', { code: formatNumber(result.response_code ?? 0) }));
        } else {
            const reason = result.failure?.name ?? result.status.name;
            ui.notify(result.response_code === null ? reason : t('webhooks.testFailedCode', { reason, code: formatNumber(result.response_code) }), 'error');
        }
    });
    testing.value = null;
}
</script>

<template>
    <div class="rounded-xl border border-line bg-surface">
        <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-end">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                <input
                    v-model="filters.search"
                    type="search"
                    :placeholder="t('webhooks.searchPlaceholder')"
                    :aria-label="t('webhooks.searchPlaceholder')"
                    class="h-10 w-full rounded-lg border border-line bg-surface pr-3 pl-9 text-sm focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                />
            </div>
            <UiSelect v-model="filters.enabled" :label="t('common.status')" :placeholder="t('common.all')" :options="[{ value: '1', label: t('webhooks.on') }, { value: '0', label: t('webhooks.off') }]" />
            <UiButton v-if="filtered" variant="ghost" @click="list.resetFilters()"><RotateCcw class="size-4" />{{ t('common.reset') }}</UiButton>
            <UiButton :disabled="!groups.length" data-webhook-new @click="openCreate"><Plus class="size-4" />{{ t('webhooks.new') }}</UiButton>
        </div>

        <ul class="divide-y divide-line" :class="loading && 'opacity-50'" data-webhook-list>
            <li v-for="webhook in rows" :key="webhook.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 text-sm" data-webhook-row>
                <div class="min-w-48 flex-1">
                    <p class="break-words font-medium text-fg" dir="auto">{{ webhook.name }}</p>
                    <p class="break-all text-xs text-muted" dir="ltr">{{ webhook.url }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-1">
                    <UiBadge v-if="turnedItselfOff(webhook)" tone="danger" data-webhook-self-off>{{ t('webhooks.turnedItselfOff') }}</UiBadge>
                    <UiBadge v-else :tone="webhook.enabled ? 'success' : 'neutral'">{{ t(webhook.enabled ? 'webhooks.on' : 'webhooks.off') }}</UiBadge>
                    <UiBadge>{{ t('webhooks.eventCount', { count: formatNumber(webhook.events.length) }, webhook.events.length) }}</UiBadge>
                    <UiBadge v-if="webhook.failure_count > 0" tone="warning" data-webhook-failures>{{ disableAfter ? t('webhooks.failuresOf', { count: formatNumber(webhook.failure_count), limit: formatNumber(disableAfter) }, webhook.failure_count) : t('webhooks.failuresInRow', { count: formatNumber(webhook.failure_count) }, webhook.failure_count) }}</UiBadge>
                </div>
                <div class="ml-auto flex flex-wrap items-center gap-1">
                    <UiButton v-if="!webhook.enabled" variant="secondary" data-webhook-turn-on @click="turnOn(webhook)"><Power class="size-4" />{{ t('webhooks.turnOn') }}</UiButton>
                    <UiButton variant="secondary" :loading="testing === webhook.id" data-webhook-test @click="test(webhook)"><Send class="size-4" />{{ t('webhooks.test') }}</UiButton>
                    <UiButton variant="secondary" data-webhook-log-open @click="logOf = webhook.id"><History class="size-4" />{{ t('webhooks.log.open') }}</UiButton>
                    <div class="flex items-center gap-1">
                        <button type="button" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('webhooks.secret.rotate')" :title="t('webhooks.secret.rotate')" data-webhook-rotate @click="rotate(webhook)">
                            <KeyRound class="size-4" />
                        </button>
                        <button type="button" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :aria-label="t('common.edit')" data-webhook-edit @click="openEdit(webhook)">
                            <Pencil class="size-4" />
                        </button>
                        <button type="button" class="rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger" :aria-label="t('common.delete')" data-webhook-delete @click="remove(webhook)">
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>
            </li>
        </ul>
        <UiEmpty v-if="!loading && !rows.length" :title="filtered ? t('common.noResults') : t('webhooks.empty')" :hint="filtered ? t('common.noResultsHint') : t('webhooks.emptyHint')" data-webhook-empty />
        <ListPagination :list="list" />

        <WebhookFormDialog v-model:open="formOpen" :webhook="editing" :groups="groups" @created="created" @saved="list.load()" />
        <WebhookSecretDialog v-model:open="secretOpen" :name="shown.name" :secret="shown.secret" />
        <WebhookLogDialog v-if="logOf !== null" :key="logOf" :webhook-id="logOf" @close="closeLog" />
    </div>
</template>
