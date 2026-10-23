<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { toApiError } from '@/lib/http';
import { identify, verify, type PublicBookingInfo, type PublicPerson } from '@/lib/appointments/public';

/**
 * Proving who one is on the public link (5.6): the employee number, then what the organization asks — the code
 * sent to the e-mail/phone on record, the date of birth or the national ID number. The page never says whether a
 * number is on record; a wrong answer says how many tries are left, and running out starts again.
 */
const props = defineProps<{ organization: string; info: PublicBookingInfo; note?: string | null }>();
const emit = defineEmits<{ 'signed-in': [person: PublicPerson] }>();
const { t } = useI18n();

const employeeNo = ref('');
const challenge = ref<{ challenge: string; method: PublicBookingInfo['verification']['method']; channels: string[] } | null>(null);
const answer = ref('');
const busy = ref(false);
const error = ref<string | null>(null);

const channelNames = computed(() => (challenge.value?.channels ?? []).map((channel) => t(`publicBooking.channels.${channel}`)).join(t('publicBooking.or')));

async function start(): Promise<void> {
    if (!employeeNo.value.trim()) {
        return;
    }
    busy.value = true;
    error.value = null;
    try {
        challenge.value = await identify(props.organization, employeeNo.value.trim());
        answer.value = '';
    } catch (failure) {
        const apiError = toApiError(failure);
        error.value = apiError.errors.employee_no?.[0] ?? apiError.message;
    } finally {
        busy.value = false;
    }
}

async function prove(): Promise<void> {
    const held = challenge.value;
    if (!held || !answer.value.trim()) {
        return;
    }
    busy.value = true;
    error.value = null;
    try {
        const field = held.method === 'code' ? 'code' : held.method;
        emit('signed-in', await verify(props.organization, { challenge: held.challenge, [field]: answer.value.trim() }));
    } catch (failure) {
        const apiError = toApiError(failure);
        // The challenge is over (no tries left, or expired): back to the number
        if (apiError.errors.challenge) {
            challenge.value = null;
        }
        error.value = Object.values(apiError.errors)[0]?.[0] ?? apiError.message;
    } finally {
        busy.value = false;
    }
}
function again(): void {
    challenge.value = null;
    error.value = null;
}
</script>

<template>
    <div class="mx-auto max-w-md space-y-4" data-public-identify>
        <p v-if="note" class="rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-fg" data-public-ended>{{ note }}</p>

        <form v-if="!challenge" class="space-y-4" data-public-step="number" @submit.prevent="start">
            <div>
                <h1 class="text-lg font-semibold text-fg">{{ t('publicBooking.identify.title') }}</h1>
                <p class="mt-1 text-sm text-muted">{{ t('publicBooking.identify.hint') }}</p>
            </div>
            <UiInput v-model="employeeNo" :label="t('publicBooking.identify.employeeNo')" autocomplete="username" autofocus :error="error ?? undefined" data-public-employee-no />
            <UiButton type="submit" block :loading="busy" :disabled="!employeeNo.trim()" data-public-continue>{{ t('publicBooking.identify.continue') }}</UiButton>
        </form>

        <form v-else class="space-y-4" :data-public-step="challenge.method" @submit.prevent="prove">
            <div>
                <h1 class="text-lg font-semibold text-fg">{{ t(`publicBooking.verify.${challenge.method}.title`) }}</h1>
                <p class="mt-1 text-sm text-muted" data-public-verify-hint>{{ t(`publicBooking.verify.${challenge.method}.hint`, { channels: channelNames }) }}</p>
            </div>
            <UiInput
                v-if="challenge.method === 'code'"
                v-model="answer"
                :label="t('publicBooking.verify.code.label')"
                inputmode="numeric"
                autocomplete="one-time-code"
                :maxlength="12"
                autofocus
                :error="error ?? undefined"
                data-public-answer
            />
            <UiInput v-else-if="challenge.method === 'date_of_birth'" v-model="answer" type="date" :label="t('publicBooking.verify.date_of_birth.label')" autocomplete="bday" :error="error ?? undefined" data-public-answer />
            <UiInput v-else v-model="answer" :label="t('publicBooking.verify.national_id.label')" inputmode="numeric" autocomplete="off" :error="error ?? undefined" data-public-answer />
            <UiButton type="submit" block :loading="busy" :disabled="!answer.trim()" data-public-verify>{{ t('publicBooking.verify.submit') }}</UiButton>
            <button type="button" class="block w-full text-center text-sm text-brand-ink hover:underline" data-public-again @click="again">{{ t('publicBooking.verify.again') }}</button>
        </form>
        <p v-if="!challenge && error === null" class="text-xs text-muted">{{ t('publicBooking.identify.privacy') }}</p>
    </div>
</template>
