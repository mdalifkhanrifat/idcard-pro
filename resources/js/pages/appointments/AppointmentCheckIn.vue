<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ScanLine, Search } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import { findByNumber, type Appointment } from '@/lib/appointments/bookings';
import { cameraAvailable } from '@/lib/appointments/scan';
import { toApiError } from '@/lib/http';
import AppointmentScanDialog from '@/pages/appointments/AppointmentScanDialog.vue';
import { useAuthStore } from '@/stores/auth';

/**
 * Finding an appointment by its number at the desk (5.5e): typed, from a barcode scanner (which types it and
 * presses Enter) or from its QR code through the camera. The one found is handed on to open its details, where
 * coming in is one press; the box empties for the next person.
 */
const emit = defineEmits<{ found: [appointment: Appointment] }>();
const { t } = useI18n();
const auth = useAuthStore();

const number = ref('');
const searching = ref(false);
const message = ref<string | null>(null);
const scanning = ref(false);
const input = ref<HTMLInputElement | null>(null);
const camera = cameraAvailable();
/** Those who take appointments a step check people in; others only find them. */
const checksIn = computed(() => auth.can('appointments.manage'));

async function find(value = number.value): Promise<void> {
    const wanted = value.trim();
    if (!wanted) {
        return;
    }
    searching.value = true;
    message.value = null;
    try {
        const found = await findByNumber(wanted);
        if (found) {
            number.value = '';
            emit('found', found);
        } else {
            message.value = t('appointments.checkIn.notFound', { number: wanted });
        }
    } catch (error) {
        message.value = toApiError(error).message;
    } finally {
        searching.value = false;
    }
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="mb-4">
    <form class="rounded-xl border border-line bg-surface p-4" data-check-in @submit.prevent="find()">
        <label for="check-in-number" class="mb-1.5 block text-sm font-medium text-fg">{{ checksIn ? t('appointments.checkIn.title') : t('appointments.checkIn.findTitle') }}</label>
        <div class="flex flex-wrap gap-2">
            <input
                id="check-in-number"
                ref="input"
                v-model="number"
                type="text"
                inputmode="text"
                autocomplete="off"
                spellcheck="false"
                :placeholder="t('appointments.checkIn.placeholder')"
                class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-3 py-2 font-mono text-sm text-fg placeholder:font-sans placeholder:text-muted max-sm:basis-full"
                data-check-in-number
            />
            <UiButton type="submit" variant="secondary" :loading="searching" :disabled="!number.trim()" class="whitespace-nowrap" data-check-in-find><Search class="size-4" />{{ t('appointments.checkIn.find') }}</UiButton>
            <UiButton v-if="camera" variant="secondary" class="whitespace-nowrap" data-check-in-scan @click="scanning = true"><ScanLine class="size-4" />{{ t('appointments.checkIn.scan') }}</UiButton>
        </div>
        <p v-if="message" role="alert" class="mt-2 text-sm text-danger" data-check-in-message>{{ message }}</p>
        <p v-else class="mt-2 text-xs text-muted">{{ t('appointments.checkIn.hint') }}</p>
    </form>
    <AppointmentScanDialog v-if="camera" v-model:open="scanning" @scanned="find" />
    </div>
</template>
