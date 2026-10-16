<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Trash2 } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiRadioGroup from '@/components/ui/UiRadioGroup.vue';
import { formatNumber } from '@/lib/format';
import type { AppointmentOptions, AppointmentSettings, PlaceCounts } from '@/lib/appointments/api';
import { numberSample } from '@/lib/appointments/numbers';
import AppointmentSummary from '@/pages/appointments/AppointmentSummary.vue';

/**
 * The organization's appointments as a whole: on or off, how booking works, what people may do through the public
 * link and how they prove who they are, and the reminders. Every choice and its name come from the server
 * (options), the limits too.
 */
const props = defineProps<{ options: AppointmentOptions; errors: Record<string, string[]>; disabled?: boolean; places?: PlaceCounts }>();
const settings = defineModel<AppointmentSettings>({ required: true });
const { t } = useI18n();

/** The server's first message for a field or anything inside it (e.g. appointments.booking.0). */
const error = (field: string) => Object.entries(props.errors).find(([key]) => key === `appointments.${field}` || key.startsWith(`appointments.${field}.`))?.[1];
const choices = (group: 'booking' | 'scheduling' | 'confirmation' | 'verification' | 'code_channels') =>
    props.options[group].map((value) => ({ value, label: props.options.labels[group][value] ?? value }));
const limit = (key: string) => props.options.limits[key] ?? [0, 0];
const range = (key: string) => t('appointments.general.range', { min: formatNumber(limit(key)[0]), max: formatNumber(limit(key)[1]) });

/** A number setting bound to a text box: the box gives text, the settings keep a number. */
function numberOf(read: () => number, write: (value: number) => void) {
    return computed({ get: read, set: (value: number | string | null) => write(Number(value)) });
}
const slotMinutes = numberOf(() => settings.value.slot_minutes, (value) => (settings.value.slot_minutes = value));
const openPerService = numberOf(() => settings.value.open_per_service, (value) => (settings.value.open_per_service = value));
const noticeHours = numberOf(() => settings.value.notice_hours, (value) => (settings.value.notice_hours = value));
const daysAhead = numberOf(() => settings.value.days_ahead, (value) => (settings.value.days_ahead = value));
const untilHours = numberOf(() => settings.value.changes.until_hours, (value) => (settings.value.changes.until_hours = value));
const codeMinutes = numberOf(() => settings.value.verification.code_minutes, (value) => (settings.value.verification.code_minutes = value));
const codeAttempts = numberOf(() => settings.value.verification.code_attempts, (value) => (settings.value.verification.code_attempts = value));

const publicLink = computed(() => settings.value.booking.includes('public'));
/** The format at work today, as the 42nd booking (an identifier: its digits stay as stored, like card numbers). */
const sample = computed(() => numberSample(settings.value.number_format, new Date(), 42));

function toggleIn(list: string[], value: string, on: boolean): string[] {
    return on ? [...list.filter((item) => item !== value), value] : list.filter((item) => item !== value);
}
const maxReminders = computed(() => limit('reminders')[1]);
function addReminder(): void {
    const taken = new Set(settings.value.reminders);
    const hours = [24, 2, 48, 1, 72].find((value) => !taken.has(value)) ?? limit('reminder_hours')[0];
    settings.value.reminders = [...settings.value.reminders, hours];
}
function setReminder(index: number, value: number | string | null | undefined): void {
    settings.value.reminders = settings.value.reminders.map((hours, at) => (at === index ? Number(value) : hours));
}
</script>

<template>
    <div class="space-y-6">
        <UiCard :title="t('appointments.general.title')" :description="t('appointments.general.description')" data-general-card>
            <AppointmentSummary :settings="settings" :options="options" :places="places" />
            <fieldset :disabled="disabled" class="mt-5 border-t border-line pt-5">
                <UiCheckbox v-model="settings.enabled" :label="t('appointments.general.enabled')" :description="t('appointments.general.enabledHint')" data-setting="enabled" />
            </fieldset>
        </UiCard>

        <UiCard :title="t('appointments.general.booking.title')" :description="t('appointments.general.booking.description')" data-booking-card>
            <fieldset :disabled="disabled" class="space-y-6">
                <div class="space-y-1.5" data-setting="booking">
                    <p class="text-sm font-medium text-fg">{{ t('appointments.general.booking.bookedBy') }}</p>
                    <div class="space-y-2">
                        <UiCheckbox
                            v-for="choice in choices('booking')"
                            :key="choice.value"
                            :model-value="settings.booking.includes(choice.value)"
                            :label="choice.label"
                            :data-booking="choice.value"
                            @update:model-value="settings.booking = toggleIn(settings.booking, choice.value, !!$event)"
                        />
                    </div>
                    <p v-if="error('booking')" class="text-xs text-danger" data-error="booking">{{ error('booking')?.[0] }}</p>
                    <p v-else class="text-xs text-muted">{{ t('appointments.general.booking.bookedByHint') }}</p>
                </div>

                <div class="grid gap-6 md:grid-cols-2">
                    <div class="space-y-3" data-setting="scheduling">
                        <UiRadioGroup v-model="settings.scheduling" :label="t('appointments.general.booking.scheduling')" :options="choices('scheduling')" :error="error('scheduling')" :disabled="disabled" />
                        <div v-if="settings.scheduling === 'slot'" class="max-w-56">
                            <UiInput v-model="slotMinutes" type="number" :min="String(limit('slot_minutes')[0])" :max="String(limit('slot_minutes')[1])" :label="t('appointments.general.booking.slotMinutes')" :hint="range('slot_minutes')" :error="error('slot_minutes')" data-setting="slot_minutes" />
                        </div>
                    </div>
                    <UiRadioGroup v-model="settings.confirmation" :label="t('appointments.general.booking.confirmation')" :options="choices('confirmation')" :error="error('confirmation')" :disabled="disabled" data-setting="confirmation" />
                </div>

                <UiCheckbox v-model="settings.family_members" :label="t('appointments.general.booking.familyMembers')" :description="t('appointments.general.booking.familyMembersHint')" data-setting="family_members" />

                <div class="grid gap-4 sm:grid-cols-3">
                    <UiInput v-model="openPerService" type="number" :min="String(limit('open_per_service')[0])" :max="String(limit('open_per_service')[1])" :label="t('appointments.general.booking.openPerService')" :hint="range('open_per_service')" :error="error('open_per_service')" data-setting="open_per_service" />
                    <UiInput v-model="noticeHours" type="number" :min="String(limit('notice_hours')[0])" :max="String(limit('notice_hours')[1])" :label="t('appointments.general.booking.noticeHours')" :hint="t('appointments.general.booking.noticeHoursHint', { range: range('notice_hours') })" :error="error('notice_hours')" data-setting="notice_hours" />
                    <UiInput v-model="daysAhead" type="number" :min="String(limit('days_ahead')[0])" :max="String(limit('days_ahead')[1])" :label="t('appointments.general.booking.daysAhead')" :hint="range('days_ahead')" :error="error('days_ahead')" data-setting="days_ahead" />
                </div>

                <div class="max-w-md space-y-1.5" data-setting="number_format">
                    <UiInput v-model="settings.number_format" :label="t('appointments.general.booking.numberFormat')" :maxlength="40" :error="error('number_format')" class="font-mono" />
                    <p class="text-xs text-muted">
                        <span v-if="sample" data-number-sample>{{ t('appointments.general.booking.numberSample', { number: sample }) }} · </span>{{ t('appointments.general.booking.numberTokens') }}
                    </p>
                </div>
            </fieldset>
        </UiCard>

        <UiCard :title="t('appointments.general.public.title')" :description="t('appointments.general.public.description')" data-public-card>
            <p v-if="!publicLink" class="mb-4 rounded-lg border border-line bg-surface-2 px-4 py-3 text-sm text-muted" data-public-off>{{ t('appointments.general.public.off') }}</p>
            <fieldset :disabled="disabled" class="space-y-6" :class="!publicLink && 'opacity-60'">
                <div class="space-y-3">
                    <UiCheckbox v-model="settings.changes.cancel" :label="t('appointments.general.public.cancel')" data-setting="changes.cancel" />
                    <UiCheckbox v-model="settings.changes.reschedule" :label="t('appointments.general.public.reschedule')" data-setting="changes.reschedule" />
                    <div v-if="settings.changes.cancel || settings.changes.reschedule" class="max-w-56">
                        <UiInput v-model="untilHours" type="number" :min="String(limit('change_until_hours')[0])" :max="String(limit('change_until_hours')[1])" :label="t('appointments.general.public.untilHours')" :hint="range('change_until_hours')" :error="error('changes.until_hours')" data-setting="changes.until_hours" />
                    </div>
                </div>

                <div class="grid gap-6 border-t border-line pt-5 md:grid-cols-2">
                    <UiRadioGroup v-model="settings.verification.method" :label="t('appointments.general.public.method')" :options="choices('verification')" :error="error('verification.method')" :disabled="disabled" data-setting="verification.method" />
                    <div v-if="settings.verification.method === 'code'" class="space-y-4">
                        <div class="space-y-1.5" data-setting="verification.code_channels">
                            <p class="text-sm font-medium text-fg">{{ t('appointments.general.public.codeChannels') }}</p>
                            <div class="flex flex-wrap gap-x-6 gap-y-2">
                                <UiCheckbox
                                    v-for="choice in choices('code_channels')"
                                    :key="choice.value"
                                    :model-value="settings.verification.code_channels.includes(choice.value)"
                                    :label="choice.label"
                                    :data-code-channel="choice.value"
                                    @update:model-value="settings.verification.code_channels = toggleIn(settings.verification.code_channels, choice.value, !!$event)"
                                />
                            </div>
                            <p v-if="error('verification.code_channels')" class="text-xs text-danger">{{ error('verification.code_channels')?.[0] }}</p>
                            <p v-else class="text-xs text-muted">{{ t('appointments.general.public.codeChannelsHint') }}</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <UiInput v-model="codeMinutes" type="number" :min="String(limit('code_minutes')[0])" :max="String(limit('code_minutes')[1])" :label="t('appointments.general.public.codeMinutes')" :hint="range('code_minutes')" :error="error('verification.code_minutes')" data-setting="verification.code_minutes" />
                            <UiInput v-model="codeAttempts" type="number" :min="String(limit('code_attempts')[0])" :max="String(limit('code_attempts')[1])" :label="t('appointments.general.public.codeAttempts')" :hint="range('code_attempts')" :error="error('verification.code_attempts')" data-setting="verification.code_attempts" />
                        </div>
                    </div>
                </div>
            </fieldset>
        </UiCard>

        <UiCard :title="t('appointments.general.reminders.title')" :description="t('appointments.general.reminders.description')" data-reminders-card>
            <fieldset :disabled="disabled" class="space-y-3">
                <p v-if="!settings.reminders.length" class="text-sm text-muted">{{ t('appointments.general.reminders.none') }}</p>
                <div v-for="(hours, index) in settings.reminders" :key="index" class="flex items-end gap-2" data-reminder>
                    <div class="w-40">
                        <UiInput :model-value="hours" type="number" :min="String(limit('reminder_hours')[0])" :max="String(limit('reminder_hours')[1])" :label="t('appointments.general.reminders.hours')" :error="error(`reminders.${index}`)" @update:model-value="setReminder(index, $event)" />
                    </div>
                    <UiButton variant="ghost" size="sm" :aria-label="t('appointments.general.reminders.remove')" class="mb-0.5" @click="settings.reminders = settings.reminders.filter((_, at) => at !== index)"><Trash2 class="size-4" /></UiButton>
                </div>
                <p class="text-xs" :class="error('reminders') ? 'text-danger' : 'text-muted'">{{ error('reminders')?.[0] ?? t('appointments.general.reminders.hint', { range: range('reminder_hours'), max: formatNumber(maxReminders) }) }}</p>
                <UiButton v-if="settings.reminders.length < maxReminders" variant="secondary" size="sm" data-add-reminder @click="addReminder"><Plus class="size-4" />{{ t('appointments.general.reminders.add') }}</UiButton>
            </fieldset>
        </UiCard>
    </div>
</template>
