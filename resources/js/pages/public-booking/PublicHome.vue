<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { CalendarClock, CalendarPlus, CheckCircle2, LogOut, MapPin } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { statusTone, timeRange } from '@/lib/appointments/bookings';
import { cancelPublic, myAppointments, signOut, type PublicAppointment, type PublicBookingInfo, type PublicPerson } from '@/lib/appointments/public';
import { formatLongDate, formatNumber, localDigits } from '@/lib/format';
import { toApiError } from '@/lib/http';
import PublicBookForm from './PublicBookForm.vue';

/**
 * A signed-in person on the public link (5.6): their appointments and their family members' from today on — each
 * with cancelling and moving as the organization allows, or why not —, booking a new one, and what was just booked
 * (its number, to show at the desk). A session that ended goes back to proving who one is.
 */
const props = defineProps<{ organization: string; info: PublicBookingInfo; person: PublicPerson }>();
const emit = defineEmits<{ 'signed-out': [note: string | null] }>();
const { t } = useI18n();

const appointments = ref<PublicAppointment[]>([]);
const loading = ref(true);
const failed = ref(false);
const mode = ref<'list' | 'book' | 'move'>('list');
const moving = ref<PublicAppointment | null>(null);
/** What was just booked or moved: shown on top until the next action. */
const done = ref<{ appointment: PublicAppointment; moved: boolean } | null>(null);
const cancelling = ref<string | null>(null);
const reason = ref('');
const busy = ref(false);
const refusal = ref<string | null>(null);

function ended(): void {
    emit('signed-out', t('publicBooking.ended'));
}
async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    try {
        appointments.value = await myAppointments(props.organization);
    } catch (failure) {
        if (toApiError(failure).status === 401) {
            ended();
            return;
        }
        failed.value = true;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const when = (a: PublicAppointment) => {
    const range = timeRange(a);

    return `${formatLongDate(a.date)} · ${range ? localDigits(range) : t('appointments.list.inTurn')}`;
};
const whom = (a: PublicAppointment) => (a.family_member ? `${a.family_member.name} · ${t(`cardholders.family.relations.${a.family_member.relation}`)}` : props.person.name);
const blocked = (a: PublicAppointment) => [a.actions.move, a.actions.cancel].filter((verdict) => !verdict.allowed && verdict.reason).map((verdict) => verdict.reason).filter((text, index, all) => all.indexOf(text) === index);
const upcoming = computed(() => appointments.value.filter((a) => a.status.open));
const others = computed(() => appointments.value.filter((a) => !a.status.open));

function startBooking(): void {
    done.value = null;
    refusal.value = null;
    mode.value = 'book';
}
function startMoving(a: PublicAppointment): void {
    done.value = null;
    refusal.value = null;
    moving.value = a;
    mode.value = 'move';
}
function finished(appointment: PublicAppointment): void {
    done.value = { appointment, moved: mode.value === 'move' };
    mode.value = 'list';
    moving.value = null;
    load();
}
async function cancel(a: PublicAppointment): Promise<void> {
    busy.value = true;
    refusal.value = null;
    try {
        await cancelPublic(props.organization, a.id, reason.value.trim() || null);
        cancelling.value = null;
        done.value = null;
        await load();
    } catch (failure) {
        const apiError = toApiError(failure);
        if (apiError.status === 401) {
            ended();
            return;
        }
        refusal.value = apiError.errors.status?.[0] ?? apiError.message;
        await load();
    } finally {
        busy.value = false;
    }
}
async function leave(): Promise<void> {
    await signOut(props.organization);
    emit('signed-out', null);
}
</script>

<template>
    <div class="space-y-5" data-public-home>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-lg font-semibold break-words text-fg" data-public-greeting>{{ t('publicBooking.home.hello', { name: person.name }) }}</h1>
                <p v-if="person.employee_no" class="text-xs text-muted">{{ t('appointments.detail.employeeNo') }}: <span class="font-mono">{{ person.employee_no }}</span></p>
            </div>
            <UiButton variant="ghost" size="sm" data-public-sign-out @click="leave"><LogOut class="size-4" />{{ t('publicBooking.home.signOut') }}</UiButton>
        </div>

        <!-- What was just done: its number to show at the desk -->
        <div v-if="done" class="flex gap-3 rounded-xl border border-success/30 bg-success/10 p-4" data-public-done>
            <CheckCircle2 class="size-6 shrink-0 text-success" />
            <div class="min-w-0 text-sm">
                <p class="font-semibold text-fg">{{ done.moved ? t('publicBooking.done.moved') : done.appointment.status.key === 'pending' ? t('publicBooking.done.pending') : t('publicBooking.done.booked') }}</p>
                <p class="mt-1 text-fg">{{ t('publicBooking.done.number') }}: <span class="font-mono font-semibold" data-public-done-number>{{ done.appointment.number }}</span></p>
                <p class="text-fg">{{ when(done.appointment) }} · {{ done.appointment.location?.name }}</p>
                <p class="mt-1 text-xs text-muted">{{ t('publicBooking.done.hint') }}</p>
            </div>
        </div>

        <PublicBookForm v-if="mode !== 'list'" :organization="organization" :info="info" :person="person" :moving="mode === 'move' ? moving : null" @done="finished" @cancel="mode = 'list'" @signed-out="ended" />

        <template v-else>
            <UiButton block data-public-book @click="startBooking"><CalendarPlus class="size-4" />{{ t('publicBooking.home.book') }}</UiButton>
            <p v-if="refusal" role="alert" class="rounded-lg border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-fg" data-public-refusal>{{ refusal }}</p>

            <section class="space-y-3">
                <h2 class="text-sm font-semibold text-fg">{{ t('publicBooking.home.mine') }}<template v-if="upcoming.length"> ({{ formatNumber(upcoming.length) }})</template></h2>
                <p v-if="loading" class="text-sm text-muted">{{ t('common.loading') }}</p>
                <div v-else-if="failed" class="flex flex-col items-start gap-2 text-sm text-muted">{{ t('publicBooking.home.failed') }}<UiButton variant="secondary" size="sm" @click="load">{{ t('appointments.retry') }}</UiButton></div>
                <p v-else-if="!appointments.length" class="rounded-lg border border-dashed border-line px-4 py-6 text-center text-sm text-muted" data-public-none>{{ t('publicBooking.home.none') }}</p>
                <ul v-else class="space-y-3">
                    <li v-for="a in [...upcoming, ...others]" :key="a.id" class="rounded-xl border border-line p-4 text-sm" :data-public-appointment="a.number">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold break-words text-fg">{{ a.service.name }} · <span class="font-mono">{{ a.number }}</span></p>
                                <p class="text-fg">{{ when(a) }}</p>
                                <p v-if="a.location" class="flex items-start gap-1 text-muted"><MapPin class="mt-0.5 size-3.5 shrink-0" /><span class="break-words">{{ a.location.name }}<template v-if="a.location.address"> — {{ a.location.address }}</template></span></p>
                                <p class="text-xs text-muted">{{ t('publicBooking.home.for') }}: {{ whom(a) }}</p>
                            </div>
                            <UiBadge :tone="statusTone(a.status.key)" :class="a.status.key === 'cancelled' && 'line-through'">{{ a.status.name }}</UiBadge>
                        </div>
                        <p v-if="a.note" class="mt-2 whitespace-pre-line break-words text-muted" dir="auto">{{ a.note }}</p>

                        <div v-if="cancelling === a.id" class="mt-3 space-y-2 border-t border-line pt-3" data-public-cancel-form>
                            <UiTextarea v-model="reason" :label="t('appointments.detail.reason')" :rows="2" :maxlength="500" data-public-reason />
                            <div class="flex flex-wrap justify-end gap-2">
                                <UiButton variant="secondary" size="sm" :disabled="busy" @click="cancelling = null">{{ t('appointments.detail.keep') }}</UiButton>
                                <UiButton variant="danger" size="sm" :loading="busy" data-public-cancel-submit @click="cancel(a)">{{ t('appointments.detail.cancel') }}</UiButton>
                            </div>
                        </div>
                        <div v-else-if="a.status.open" class="mt-3 space-y-2 border-t border-line pt-3">
                            <div class="flex flex-wrap gap-2">
                                <UiButton variant="secondary" size="sm" :disabled="!a.actions.move.allowed" data-public-move @click="startMoving(a)"><CalendarClock class="size-4" />{{ t('appointments.detail.move') }}</UiButton>
                                <UiButton variant="secondary" size="sm" :disabled="!a.actions.cancel.allowed" data-public-cancel @click="cancelling = a.id; reason = ''">{{ t('appointments.detail.cancel') }}</UiButton>
                            </div>
                            <p v-for="text in blocked(a)" :key="text ?? ''" class="text-xs text-muted" data-public-blocked>{{ text }}</p>
                        </div>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
