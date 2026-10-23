<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ExternalLink, Undo2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { useConfirm } from '@/composables/useConfirm';
import { cancelAppointment, changeAppointmentStatus, fetchAppointment, layoutActions, statusTone, timeRange, type Appointment, type AppointmentStep } from '@/lib/appointments/bookings';
import { formatDateTime, formatLongDate, localDigits } from '@/lib/format';
import { toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';

/**
 * One appointment (5.5c): who, what, where and when, who booked it and who took its last step — and only the
 * buttons the server offers (`actions`): the steps ahead first, calling it off with a reason, and going back from
 * a mistake apart. A button not possible now says why underneath (a phone has no hover). Read afresh each time it
 * opens and after a refusal (time passing changes what is possible); a change's own answer redraws it.
 */
const props = defineProps<{ appointmentId: string | null }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ changed: [appointment: Appointment] }>();
const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();
const timeZone = computed(() => auth.tenant?.timezone);

const appointment = ref<Appointment | null>(null);
const loading = ref(false);
const failure = ref<'missing' | 'failed' | null>(null);
/** The server's refusal of the last change, shown until the next one. */
const refusal = ref<string | null>(null);
const busy = ref<string | null>(null);
const stopping = ref(false);
const reason = ref('');
const reasonError = ref<string | undefined>();

async function load(quiet = false): Promise<void> {
    const id = props.appointmentId;
    if (!id) {
        return;
    }
    loading.value = !quiet;
    try {
        const found = await fetchAppointment(id);
        // Another one opened meanwhile: its answer, not this one, is shown
        if (id === props.appointmentId) {
            appointment.value = found;
            failure.value = null;
        }
    } catch (error) {
        if (id === props.appointmentId && !quiet) {
            failure.value = toApiError(error).status === 404 ? 'missing' : 'failed';
        }
    } finally {
        loading.value = false;
    }
}

watch([open, () => props.appointmentId], ([isOpen, id], [wasOpen, previousId]) => {
    if (isOpen && id && (!wasOpen || id !== previousId)) {
        appointment.value = null;
        failure.value = null;
        refusal.value = null;
        stopping.value = false;
        load();
    }
}, { immediate: true });

const actions = computed(() => layoutActions(appointment.value?.actions, appointment.value?.status.open ?? true));
const blocked = computed(() => [...actions.value.forward, ...(actions.value.stop ? [actions.value.stop] : [])].filter((action) => !action.allowed && action.reason));
const stopLabel = computed(() => actions.value.stop?.label ?? t('appointments.detail.cancel'));

/** A change's answer: drawn at once (with its new actions), and the page behind reloads. */
function changed(next: Appointment, message: string): void {
    appointment.value = next;
    refusal.value = null;
    ui.notify(message, 'success');
    emit('changed', next);
}
async function refused(error: unknown): Promise<void> {
    const apiError = toApiError(error);
    refusal.value = apiError.errors.status?.[0] ?? apiError.errors.reason?.[0] ?? apiError.message;
    reasonError.value = apiError.errors.reason?.[0];
    ui.notify(refusal.value, 'error');
    await load(true);
}

async function step(to: AppointmentStep): Promise<void> {
    const current = appointment.value;
    if (!current || !to.allowed) {
        return;
    }
    if (to.correction && !(await confirm({ title: t('appointments.detail.correctionConfirm', { label: to.label }), message: t('appointments.detail.correctionMessage', { status: to.status.name }), confirmLabel: to.label }))) {
        return;
    }
    busy.value = to.status.key;
    try {
        const next = await changeAppointmentStatus(current.id, to.status.key);
        changed(next, t('appointments.detail.stepDone', { number: next.number, status: next.status.name }));
    } catch (error) {
        await refused(error);
    } finally {
        busy.value = null;
    }
}

function startStop(): void {
    stopping.value = true;
    reason.value = '';
    reasonError.value = undefined;
}
async function stop(): Promise<void> {
    const current = appointment.value;
    const how = actions.value.stop;
    if (!current || !how?.allowed) {
        return;
    }
    busy.value = 'cancelled';
    const given = reason.value.trim() || null;
    try {
        const next = how.via === 'cancel' ? await cancelAppointment(current.id, given) : await changeAppointmentStatus(current.id, 'cancelled', given);
        stopping.value = false;
        changed(next, t('appointments.detail.stepDone', { number: next.number, status: next.status.name }));
    } catch (error) {
        await refused(error);
    } finally {
        busy.value = null;
    }
}

const title = computed(() => (appointment.value ? t('appointments.detail.title', { number: appointment.value.number }) : t('appointments.detail.loadingTitle')));
const when = computed(() => {
    const shown = appointment.value;
    if (!shown) {
        return '';
    }
    const range = timeRange(shown);

    return `${formatLongDate(shown.date)} · ${range ? localDigits(range) : t('appointments.list.inTurn')}`;
});
const relation = (key: string) => t(`cardholders.family.relations.${key}`);
const by = (name: string | undefined, at: string | null) => [name, formatDateTime(at, timeZone.value)].filter(Boolean).join(' · ');
</script>

<template>
    <UiDialog v-model:open="open" :title="title" size="lg" full-on-phone>
        <p v-if="loading && !appointment" class="py-8 text-center text-sm text-muted">{{ t('common.loading') }}</p>
        <div v-else-if="failure" class="flex flex-col items-center gap-3 py-8 text-center text-sm text-muted" data-detail-failed>
            {{ t(`appointments.detail.${failure}`) }}
            <UiButton v-if="failure === 'failed'" variant="secondary" size="sm" @click="load()">{{ t('appointments.retry') }}</UiButton>
        </div>
        <div v-else-if="appointment" class="space-y-5 text-sm" data-detail>
            <UiBadge :tone="statusTone(appointment.status.key)" :class="appointment.status.key === 'cancelled' && 'line-through'" data-detail-status>{{ appointment.status.name }}</UiBadge>

            <!-- Who -->
            <section class="rounded-lg border border-line px-4 py-3" data-detail-person>
                <p class="font-medium break-words text-fg">{{ appointment.family_member?.name ?? appointment.cardholder?.name }}</p>
                <p v-if="appointment.family_member && appointment.cardholder" class="break-words text-muted">{{ t('appointments.detail.memberOf', { relation: relation(appointment.family_member.relation), name: appointment.cardholder.name }) }}</p>
                <p v-if="appointment.cardholder?.employee_no" class="text-xs text-muted">{{ t('appointments.detail.employeeNo') }}: <span class="font-mono">{{ appointment.cardholder.employee_no }}</span></p>
                <RouterLink v-if="appointment.cardholder && auth.can('cardholders.view')" :to="`/cardholders/${appointment.cardholder.id}`" class="mt-1 inline-flex items-center gap-1 text-xs text-brand-ink hover:underline" data-detail-cardholder>
                    <ExternalLink class="size-3.5" />{{ t('appointments.detail.openCardholder') }}
                </RouterLink>
            </section>

            <dl class="space-y-2">
                <div class="flex flex-wrap justify-between gap-x-3"><dt class="text-muted">{{ t('appointments.detail.when') }}</dt><dd class="text-right break-words text-fg" data-detail-when>{{ when }}</dd></div>
                <div class="flex flex-wrap justify-between gap-x-3"><dt class="text-muted">{{ t('appointments.list.service') }}</dt><dd class="text-right break-words text-fg">{{ appointment.service.name }}</dd></div>
                <div v-if="appointment.location" class="flex flex-wrap justify-between gap-x-3"><dt class="text-muted">{{ t('appointments.list.place') }}</dt><dd class="text-right break-words text-fg">{{ appointment.location.name }}</dd></div>
                <div class="flex flex-wrap justify-between gap-x-3"><dt class="text-muted">{{ t('appointments.detail.source') }}</dt><dd class="text-right text-fg" data-detail-source>{{ appointment.source.name }}</dd></div>
                <div class="flex flex-wrap justify-between gap-x-3"><dt class="text-muted">{{ t('appointments.detail.booked') }}</dt><dd class="text-right break-words text-fg" data-detail-booked>{{ by(appointment.booked_by?.name, appointment.created_at) }}</dd></div>
                <div v-if="appointment.status_changed_at" class="flex flex-wrap justify-between gap-x-3"><dt class="text-muted">{{ t('appointments.detail.lastStep') }}</dt><dd class="text-right break-words text-fg" data-detail-last-step>{{ by(appointment.status_changed_by?.name, appointment.status_changed_at) }}</dd></div>
            </dl>
            <div v-if="appointment.note" class="space-y-1"><p class="text-muted">{{ t('appointments.detail.note') }}</p><p class="whitespace-pre-line break-words text-fg" dir="auto" data-detail-note>{{ appointment.note }}</p></div>
            <div v-if="appointment.cancel_reason" class="space-y-1"><p class="text-muted">{{ t('appointments.detail.cancelReason') }}</p><p class="whitespace-pre-line break-words text-fg" dir="auto" data-detail-cancel-reason>{{ appointment.cancel_reason }}</p></div>

            <p v-if="refusal" role="alert" class="rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-fg" data-detail-refusal>{{ refusal }}</p>

            <!-- Calling it off: a reason, then the server -->
            <section v-if="stopping && actions.stop" class="space-y-3 rounded-lg border border-line p-4" data-detail-stop-form>
                <p class="font-medium text-fg">{{ stopLabel }}</p>
                <p class="text-muted">{{ t('appointments.detail.cancelHint') }}</p>
                <UiTextarea v-model="reason" :label="t('appointments.detail.reason')" :rows="2" :maxlength="500" :error="reasonError" data-detail-reason />
                <div class="flex flex-wrap justify-end gap-2">
                    <UiButton variant="secondary" class="whitespace-nowrap" :disabled="!!busy" @click="stopping = false">{{ t('appointments.detail.keep') }}</UiButton>
                    <UiButton variant="danger" class="whitespace-nowrap" :loading="busy === 'cancelled'" data-detail-stop-submit @click="stop">{{ stopLabel }}</UiButton>
                </div>
            </section>

            <!-- What can be done -->
            <section v-else-if="actions.forward.length || actions.stop" class="space-y-2" data-detail-actions>
                <div class="flex flex-wrap gap-2">
                    <UiButton
                        v-for="(next, index) in actions.forward"
                        :key="next.status.key"
                        :variant="index === 0 ? 'primary' : 'secondary'"
                        :disabled="!next.allowed || (!!busy && busy !== next.status.key)"
                        :loading="busy === next.status.key"
                        class="whitespace-nowrap"
                        :data-detail-step="next.status.key"
                        @click="step(next)"
                    >{{ next.label }}</UiButton>
                    <UiButton v-if="actions.stop" variant="secondary" class="whitespace-nowrap" :disabled="!actions.stop.allowed || !!busy" data-detail-stop @click="startStop">{{ stopLabel }}</UiButton>
                </div>
                <ul v-if="blocked.length" class="space-y-1 text-xs text-muted" data-detail-blocked>
                    <li v-for="action in blocked" :key="'status' in action ? action.status.key : 'stop'">
                        <span class="font-medium">{{ 'status' in action ? action.label : stopLabel }}:</span> {{ action.reason }}
                    </li>
                </ul>
            </section>

            <!-- Going back from a mistake -->
            <section v-if="actions.corrections.length && !stopping" class="space-y-2 border-t border-line pt-4" data-detail-corrections>
                <p class="font-medium text-fg">{{ t('appointments.detail.corrections') }}</p>
                <p class="text-xs text-muted">{{ t('appointments.detail.correctionsHint') }}</p>
                <div class="flex flex-wrap gap-2">
                    <UiButton
                        v-for="back in actions.corrections"
                        :key="back.status.key"
                        variant="ghost"
                        size="sm"
                        :disabled="!back.allowed || (!!busy && busy !== back.status.key)"
                        :loading="busy === back.status.key"
                        :data-detail-correction="back.status.key"
                        @click="step(back)"
                    ><Undo2 class="size-4" />{{ back.label }}</UiButton>
                </div>
                <ul v-if="actions.corrections.some((back) => !back.allowed && back.reason)" class="space-y-1 text-xs text-muted">
                    <li v-for="back in actions.corrections.filter((item) => !item.allowed && item.reason)" :key="back.status.key"><span class="font-medium">{{ back.label }}:</span> {{ back.reason }}</li>
                </ul>
            </section>
        </div>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.close') }}</UiButton>
        </template>
    </UiDialog>
</template>
