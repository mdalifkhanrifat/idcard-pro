<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, FileText, Loader2, Pencil } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import CardRender from './CardRender.vue';
import { displayStatus, statusTone } from './cards';
import { http, toApiError } from '@/lib/http';
import { formatDate, formatDateTime } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { CardLayout, CardValues, IssuedCard } from '@/types';

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
const mode = ref<'view' | 'edit' | 'revoke'>('view');
const edit = reactive({ rf_card_no: '', notes: '', expiry_date: '' });
const revokeNote = ref('');
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

const status = computed(() => (card.value ? displayStatus(card.value) : 'active'));
const reasonLabel = (key: string) => (te(`cards.reasons.${key}`) ? t(`cards.reasons.${key}`) : key);

async function load(): Promise<void> {
    if (!props.cardId) {
        return;
    }
    loading.value = true;
    try {
        const { data } = await http.get(`/cards/${props.cardId}`);
        card.value = data.data;
        print.value = data.meta;
    } finally {
        loading.value = false;
    }
}

watch(open, (value) => {
    if (value) {
        mode.value = 'view';
        card.value = null;
        print.value = null;
        load();
    }
});

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
                    <UiButton v-if="auth.can('cards.update')" variant="secondary" size="sm" data-edit-card @click="startEdit"><Pencil class="size-4" />{{ t('common.edit') }}</UiButton>
                    <UiButton v-if="auth.can('cards.revoke') && card.status === 'active'" variant="secondary" size="sm" class="text-danger" data-revoke @click="mode = 'revoke'">
                        <Ban class="size-4" />{{ t('cards.revoke') }}
                    </UiButton>
                </div>
            </div>
        </div>
    </UiDialog>
</template>
