<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { formatNumber } from '@/lib/format';
import { toApiError } from '@/lib/http';
import { blankClosure, closureDays, closureForm, closureInput, saveClosure, type AppointmentClosure, type ClosureForm } from '@/lib/appointments/closures';
import type { AppointmentPlace } from '@/lib/appointments/places';
import { useUiStore } from '@/stores/ui';

/**
 * A closure, new or changed: of every place or one, its first and last day (an empty last day: one day),
 * whether it comes every year, and why, in two languages. A change sends only what changed; the server's
 * mistakes show under their fields. Read only without `canEdit`.
 */
const props = defineProps<{ closure: AppointmentClosure | null; places: AppointmentPlace[]; canEdit: boolean }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [closure: AppointmentClosure] }>();
const { t } = useI18n();
const ui = useUiStore();

const form = ref<ClosureForm>(blankClosure());
const original = ref<ClosureForm | null>(null);
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

watch(open, (value) => {
    if (!value) {
        return;
    }
    form.value = props.closure ? closureForm(props.closure) : blankClosure();
    original.value = props.closure ? { ...form.value } : null;
    errors.value = {};
});
// A first day moved past the last makes it one day again
watch(
    () => form.value.date_from,
    (from) => {
        if (form.value.date_to && from && form.value.date_to <= from) {
            form.value.date_to = '';
        }
    },
);

/** Every place, then each place (a closure's own place stays offered, even turned off). */
const placeChoices = computed(() => [{ value: '', label: t('appointments.closures.allPlaces') }, ...props.places.map((place) => ({ value: place.id, label: place.name_local ? `${place.name} · ${place.name_local}` : place.name }))]);
const span = computed(() => (form.value.date_from && form.value.date_to ? closureDays(form.value.date_from, form.value.date_to) : 1));

async function submit(): Promise<void> {
    if (!props.canEdit) {
        return;
    }
    busy.value = true;
    errors.value = {};
    try {
        const saved = await saveClosure(props.closure?.id ?? null, closureInput(form.value, original.value));
        ui.notify(t('common.saved'));
        open.value = false;
        emit('saved', saved);
    } catch (failure) {
        const apiError = toApiError(failure);
        errors.value = apiError.errors;
        ui.notify(apiError.message, 'error');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <UiDialog
        v-model:open="open"
        :title="closure ? (canEdit ? t('appointments.closures.editTitle') : t('appointments.closures.viewTitle')) : t('appointments.closures.newTitle')"
        size="lg"
        full-on-phone
    >
        <form id="closure-form" class="space-y-5" data-closure-form @submit.prevent="submit">
            <fieldset :disabled="!canEdit || busy" class="space-y-5">
                <div v-if="places.length" class="space-y-1.5" data-field="location">
                    <UiSelect v-model="form.location" :label="t('appointments.closures.place')" :options="placeChoices" :error="errors.location" />
                    <p class="text-xs text-muted">{{ t('appointments.closures.placeHint') }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiInput v-model="form.date_from" type="date" :label="t('appointments.closures.dateFrom')" required :error="errors.date_from" data-field="date_from" />
                    <UiInput
                        v-model="form.date_to"
                        type="date"
                        :min="form.date_from || undefined"
                        :label="t('appointments.closures.dateTo')"
                        :hint="span > 1 ? t('appointments.closures.span', { count: formatNumber(span) }) : t('appointments.closures.dateToHint')"
                        :error="errors.date_to"
                        data-field="date_to"
                    />
                </div>

                <UiCheckbox v-model="form.yearly" :label="t('appointments.closures.yearly')" :description="t('appointments.closures.yearlyHint')" data-field="yearly" />
                <p v-if="errors.yearly" class="text-xs text-danger">{{ errors.yearly[0] }}</p>

                <div class="space-y-1.5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiInput v-model="form.reason" :label="t('appointments.closures.reason')" :maxlength="200" :error="errors.reason" data-field="reason" dir="auto" />
                        <UiInput v-model="form.reason_local" :label="t('appointments.closures.reasonLocal')" :maxlength="200" :error="errors.reason_local" data-field="reason_local" dir="auto" />
                    </div>
                    <p class="text-xs text-muted">{{ t('appointments.closures.reasonHint') }}</p>
                </div>
            </fieldset>
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ canEdit ? t('common.cancel') : t('common.close') }}</UiButton>
            <UiButton v-if="canEdit" type="submit" form="closure-form" :loading="busy" data-save-closure>{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
