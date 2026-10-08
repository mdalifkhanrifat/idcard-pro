<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Copy, ExternalLink, FileText, Loader2, Pencil, TriangleAlert } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import CardPrintButton from './CardPrintButton.vue';
import CardRender from './CardRender.vue';
import { displayStatus, statusTone, useCardsPrinted } from './cards';
import { http, toApiError } from '@/lib/http';
import { formatDate, formatDateTime } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { CardLayout, CardPrintLog, CardValues, CardVerificationLog, IssuedCard } from '@/types';

/**
 * One issued card: the card as it prints (front and back), its record, and changing its RF number,
 * notes and expiry or revoking it.
 */
const props = defineProps<{ cardId: string | null }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ changed: [] }>();
const { t, te } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();

const card = ref<IssuedCard | null>(null);
const print = ref<{ layout: CardLayout; values: CardValues } | null>(null);
const loading = ref(false);
const prints = ref<CardPrintLog[]>([]);
/** Public checks of the card (3.7): latest first, with totals. */
const checks = ref<{ data: CardVerificationLog[]; meta: { total: number; devices: number } } | null>(null);
/** Checked after it stopped being valid: the card may still be shown somewhere. */
const checkedWhileInvalid = computed(() => (checks.value?.data ?? []).some((check) => check.status === 'revoked' || check.status === 'replaced'));
const showChecks = computed(() => !!card.value && (!!card.value.verify_url || card.value.verify_count > 0));
const CHECK_TONES = { valid: 'success', expired: 'warning', revoked: 'danger', replaced: 'danger' } as const;
const mode = ref<'view' | 'edit' | 'revoke'>('view');
const edit = reactive({ rf_card_no: '', notes: '', expiry_date: '' });
const revokeNote = ref('');
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

const status = computed(() => (card.value ? displayStatus(card.value) : 'active'));
const reasonLabel = (key: string) => (te(`cards.reasons.${key}`) ? t(`cards.reasons.${key}`) : key);
const methodLabel = (key: string) => (te(`cards.printMethods.${key}`) ? t(`cards.printMethods.${key}`) : key);

async function load(quiet = false): Promise<void> {
    if (!props.cardId) {
        return;
    }
    loading.value = !quiet;
    try {
        const [shown, history, verified] = await Promise.all([
            http.get(`/cards/${props.cardId}`),
            http.get(`/cards/${props.cardId}/prints`),
            http.get(`/cards/${props.cardId}/verifications`),
        ]);
        card.value = shown.data.data;
        print.value = shown.data.meta;
        prints.value = history.data.data;
        checks.value = verified.data;
    } finally {
        loading.value = false;
    }
}

// Printed in the print page's tab: show the new print count and history without closing the details
// (the pages behind listen for themselves)
useCardsPrinted((cardIds) => {
    if (open.value && props.cardId && cardIds.includes(props.cardId)) {
        load(true);
    }
});

watch(open, (value) => {
    if (value) {
        mode.value = 'view';
        card.value = null;
        print.value = null;
        prints.value = [];
        checks.value = null;
        load();
    }
});

async function copyLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(card.value?.verify_url ?? '');
        ui.notify(t('cards.verification.copied'));
    } catch {
        ui.notify(t('cards.verification.copyFailed'), 'error');
    }
}

function startEdit(): void {
    Object.assign(edit, { rf_card_no: card.value?.rf_card_no ?? '', notes: card.value?.notes ?? '', expiry_date: card.value?.expiry_date ?? '' });
    errors.value = {};
    mode.value = 'edit';
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        await http.patch(`/cards/${props.cardId}`, { rf_card_no: edit.rf_card_no || null, notes: edit.notes || null, expiry_date: edit.expiry_date || null });
        ui.notify(t('common.saved'));
        mode.value = 'view';
        await load();
        emit('changed');
    } catch (error) {
        errors.value = toApiError(error).errors;
    } finally {
        busy.value = false;
    }
}

async function revoke(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        await http.post(`/cards/${props.cardId}/revoke`, { note: revokeNote.value.trim() });
        ui.notify(t('cards.revoked'));
        mode.value = 'view';
        revokeNote.value = '';
        await load();
        emit('changed');
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
</script>

<template>
    <UiDialog v-model:open="open" :title="card ? card.number : t('cards.card')" size="xl">
        <div v-if="loading && !card" class="flex justify-center py-10"><Loader2 class="size-6 animate-spin text-muted" /></div>
        <div v-else-if="card" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_17rem]" data-card-detail>
            <div class="space-y-3">
                <CardRender v-if="print" :layout="print.layout" :values="print.values" :box="340" />
                <p class="text-xs text-muted">{{ t('cards.snapshotHint', { version: card.template_version }) }}</p>
            </div>

            <div class="space-y-4 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <UiBadge :tone="statusTone(status)" data-card-status>{{ t(`cards.statuses.${status}`) }}</UiBadge>
                    <UiBadge tone="neutral">{{ reasonLabel(card.reason) }}</UiBadge>
                </div>
                <dl class="space-y-2">
                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('cards.holder') }}</dt><dd class="text-right text-fg">{{ card.family_member ? card.family_member.name : card.cardholder?.full_name }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('cards.template') }}</dt><dd class="text-right text-fg">{{ card.template?.name }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('cards.issueDate') }}</dt><dd class="text-fg">{{ formatDate(card.issue_date) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('cards.expiryDate') }}</dt><dd class="text-fg">{{ card.expiry_date ? formatDate(card.expiry_date) : t('cards.noExpiry') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('cards.rfCardNo') }}</dt><dd class="text-fg">{{ card.rf_card_no ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('cards.issuedBy') }}</dt><dd class="text-right text-fg">{{ card.issued_by ?? '—' }} · {{ formatDateTime(card.created_at) }}</dd></div>
                    <div v-if="card.replaced_by" class="flex justify-between gap-3"><dt class="text-muted">{{ t('cards.replacedBy') }}</dt><dd class="font-mono text-fg">{{ card.replaced_by.number }}</dd></div>
                    <div v-if="card.status === 'revoked'" class="rounded-md bg-danger/10 p-2 text-danger">{{ t('cards.revokedNote', { note: card.status_note, date: formatDateTime(card.status_changed_at) }) }}</div>
                </dl>
                <p v-if="card.notes" class="rounded-md bg-surface-2 p-2 whitespace-pre-line text-fg">{{ card.notes }}</p>

                <!-- Every printing of this card (legacy print history) -->
                <section class="space-y-2" data-print-history>
                    <h4 class="flex items-center justify-between font-medium text-fg">
                        {{ t('cards.printHistory') }}
                        <span class="text-xs font-normal text-muted">{{ card.print_count ? t('cards.printedTimes', { count: card.print_count }) : t('cards.notPrinted') }}</span>
                    </h4>
                    <ul v-if="prints.length" class="max-h-48 space-y-2 overflow-y-auto">
                        <li v-for="log in prints" :key="log.id" class="rounded-md bg-surface-2 p-2 text-xs" data-print-log>
                            <div class="flex flex-wrap justify-between gap-x-2 text-fg">
                                <span>{{ formatDateTime(log.printed_at) }}</span>
                                <span>{{ methodLabel(log.method) }} · {{ t('cards.copiesCount', { count: log.copies }) }}</span>
                            </div>
                            <div class="text-muted">{{ log.printed_by?.name ?? '—' }}<span v-if="log.batch"> · {{ t('cards.printedInBatch') }}</span></div>
                            <p v-if="log.note" class="mt-1 whitespace-pre-line text-fg">{{ log.note }}</p>
                        </li>
                    </ul>
                </section>
                <!-- Public checks on the verification page (3.7) -->
                <section v-if="showChecks" class="space-y-2" data-verify-history>
                    <h4 class="flex items-center justify-between font-medium text-fg">
                        {{ t('cards.verification.title') }}
                        <span class="text-xs font-normal text-muted">{{ card.verify_count ? t('cards.verification.count', { count: card.verify_count, devices: checks?.meta.devices ?? 0 }) : t('cards.verification.none') }}</span>
                    </h4>
                    <div v-if="card.verify_url" class="flex items-center gap-1.5 text-xs">
                        <a :href="card.verify_url" target="_blank" rel="noopener" class="inline-flex min-w-0 items-center gap-1 text-brand-ink hover:underline" data-verify-link>
                            <ExternalLink class="size-3.5 shrink-0" /><span class="truncate">{{ t('cards.verification.open') }}</span>
                        </a>
                        <button type="button" class="rounded p-1 text-muted hover:bg-surface-2 hover:text-fg" :title="t('cards.verification.copy')" :aria-label="t('cards.verification.copy')" @click="copyLink"><Copy class="size-3.5" /></button>
                    </div>
                    <p v-if="checkedWhileInvalid" class="flex gap-1.5 rounded-md bg-danger/10 p-2 text-xs text-danger" data-verify-invalid-checks>
                        <TriangleAlert class="size-4 shrink-0" />{{ t('cards.verification.checkedWhileInvalid') }}
                    </p>
                    <ul v-if="checks?.data.length" class="max-h-40 space-y-1 overflow-y-auto">
                        <li v-for="(check, index) in checks.data" :key="index" class="flex items-center justify-between gap-2 rounded-md bg-surface-2 px-2 py-1 text-xs" data-verify-check>
                            <span class="text-fg">{{ formatDateTime(check.verified_at) }}</span>
                            <span class="flex items-center gap-2">
                                <span class="font-mono text-muted" :title="t('cards.verification.deviceHint')">{{ check.device }}</span>
                                <UiBadge :tone="CHECK_TONES[check.status]">{{ t(`verify.status.${check.status}.title`) }}</UiBadge>
                            </span>
                        </li>
                    </ul>
                </section>
                <a v-if="card.document" :href="card.document.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-fg underline-offset-2 hover:underline">
                    <FileText class="size-4" />{{ card.document.name }}
                </a>

                <!-- Edit RF number, notes, expiry -->
                <form v-if="mode === 'edit'" class="space-y-3 border-t border-line pt-3" @submit.prevent="save">
                    <UiInput v-model="edit.rf_card_no" :label="t('cards.rfCardNo')" :maxlength="60" :error="errors.rf_card_no" />
                    <UiInput v-model="edit.expiry_date" type="date" :label="t('cards.expiryDate')" :error="errors.expiry_date" />
                    <UiTextarea v-model="edit.notes" :label="t('cards.notes')" :rows="2" :maxlength="1000" :error="errors.notes" />
                    <div class="flex gap-2">
                        <UiButton type="submit" size="sm" :loading="busy">{{ t('common.save') }}</UiButton>
                        <UiButton variant="secondary" size="sm" @click="mode = 'view'">{{ t('common.cancel') }}</UiButton>
                    </div>
                </form>

                <!-- Revoke with a reason -->
                <form v-else-if="mode === 'revoke'" class="space-y-3 border-t border-line pt-3" @submit.prevent="revoke">
                    <UiInput v-model="revokeNote" :label="t('cards.revokeNote')" required :maxlength="255" :error="errors.note" data-revoke-note />
                    <p class="text-xs text-muted">{{ t('cards.revokeHint') }}</p>
                    <div class="flex gap-2">
                        <UiButton type="submit" variant="danger" size="sm" :loading="busy" data-confirm-revoke>{{ t('cards.revoke') }}</UiButton>
                        <UiButton variant="secondary" size="sm" @click="mode = 'view'">{{ t('common.cancel') }}</UiButton>
                    </div>
                </form>

                <div v-else class="flex flex-wrap gap-2 border-t border-line pt-3">
                    <CardPrintButton :card="card" />
                    <UiButton v-if="auth.can('cards.update')" variant="secondary" size="sm" data-edit-card @click="startEdit"><Pencil class="size-4" />{{ t('common.edit') }}</UiButton>
                    <UiButton v-if="auth.can('cards.revoke') && card.status === 'active'" variant="secondary" size="sm" class="text-danger" data-revoke @click="mode = 'revoke'">
                        <Ban class="size-4" />{{ t('cards.revoke') }}
                    </UiButton>
                </div>
            </div>
        </div>
    </UiDialog>
</template>
