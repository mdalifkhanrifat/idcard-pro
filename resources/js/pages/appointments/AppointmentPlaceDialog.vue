<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiRadioGroup from '@/components/ui/UiRadioGroup.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { formatNumber } from '@/lib/format';
import { toApiError } from '@/lib/http';
import type { AppointmentOptions, AppointmentSettings } from '@/lib/appointments/api';
import { hoursMistakes, savePlace, type AppointmentPlace, type PlaceInput, type WeeklyHours } from '@/lib/appointments/places';
import { serviceName } from '@/lib/appointments/services';
import WeeklyHoursEditor from '@/pages/appointments/WeeklyHoursEditor.vue';
import { useUiStore } from '@/stores/ui';

/**
 * A place, new or changed: names in two languages, address, people served at once and per day, the kinds of
 * visit it takes (every kind, those added later too — or some) and its week; on or off. A new place starts from
 * the server's week and capacities; a change sends only what changed. Read only without `canEdit`.
 */
const props = defineProps<{ place: AppointmentPlace | null; options: AppointmentOptions; settings: AppointmentSettings; canEdit: boolean; nextOrder: number }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [place: AppointmentPlace] }>();
const { t, locale } = useI18n();
const ui = useUiStore();

interface Form {
    name: string;
    name_local: string;
    address: string;
    slot_capacity: number;
    day_capacity: number;
    services: string[] | null;
    hours: WeeklyHours;
    is_active: boolean;
}
const form = ref<Form>(blank());
const original = ref<Form>(blank());
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

function blank(): Form {
    const defaults = props.options.locations;

    return { name: '', name_local: '', address: '', slot_capacity: defaults.slot_capacity, day_capacity: defaults.day_capacity, services: null, hours: JSON.parse(JSON.stringify(defaults.hours)), is_active: true };
}
watch(open, (value) => {
    if (!value) {
        return;
    }
    const p = props.place;
    form.value = p
        ? { name: p.name, name_local: p.name_local ?? '', address: p.address ?? '', slot_capacity: p.slot_capacity, day_capacity: p.day_capacity, services: p.services ? [...p.services] : null, hours: JSON.parse(JSON.stringify(p.hours)), is_active: p.is_active }
        : blank();
    original.value = JSON.parse(JSON.stringify(form.value));
    errors.value = {};
});

const limit = (key: string) => props.options.limits[key] ?? [0, 0];
const range = (key: string) => t('appointments.general.range', { min: formatNumber(limit(key)[0]), max: formatNumber(limit(key)[1]) });
const slotsMatter = computed(() => props.settings.scheduling === 'slot');
const serviceChoices = computed(() => Object.entries(props.settings.services).map(([key, service]) => ({ key, name: serviceName(key, service, props.options, locale.value), enabled: service.enabled })));
const coverage = computed({
    get: () => (form.value.services === null ? 'all' : 'some'),
    set: (value: string) => (form.value.services = value === 'all' ? null : serviceChoices.value.filter((choice) => choice.enabled).map((choice) => choice.key)),
});
function toggleService(key: string, on: boolean): void {
    const list = form.value.services ?? [];
    form.value.services = on ? [...list.filter((item) => item !== key), key] : list.filter((item) => item !== key);
}
const hasMistakes = computed(() => Object.keys(hoursMistakes(form.value.hours, props.options.locations.weekdays)).length > 0);
const error = (field: string) => errors.value[field]?.[0] ?? Object.entries(errors.value).find(([key]) => key.startsWith(`${field}.`))?.[1][0];

async function submit(): Promise<void> {
    if (hasMistakes.value || !props.canEdit) {
        return;
    }
    busy.value = true;
    errors.value = {};
    const values: PlaceInput = {
        name: form.value.name.trim(),
        name_local: form.value.name_local.trim() || null,
        address: form.value.address.trim() || null,
        slot_capacity: Number(form.value.slot_capacity),
        day_capacity: Number(form.value.day_capacity),
        services: form.value.services,
        hours: form.value.hours,
        is_active: form.value.is_active,
    };
    // A change sends only what changed; a new place everything, at the end of the list
    const before = original.value as unknown as Record<string, unknown>;
    const input = props.place
        ? (Object.fromEntries(Object.entries(values).filter(([key, value]) => JSON.stringify(value) !== JSON.stringify(key === 'name_local' || key === 'address' ? (before[key] as string).trim() || null : before[key]))) as PlaceInput)
        : { ...values, sort_order: props.nextOrder };
    try {
        const saved = await savePlace(props.place?.id ?? null, input);
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
    <UiDialog v-model:open="open" :title="place ? (canEdit ? t('appointments.places.editTitle') : place.name) : t('appointments.places.newTitle')" size="xl" full-on-phone>
        <form id="place-form" class="space-y-6" data-place-form @submit.prevent="submit">
            <fieldset :disabled="!canEdit || busy" class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiInput v-model="form.name" :label="t('appointments.places.name')" required :maxlength="120" :error="errors.name" data-field="name" dir="auto" />
                    <UiInput v-model="form.name_local" :label="t('appointments.places.nameLocal')" :maxlength="120" :error="errors.name_local" data-field="name_local" dir="auto" />
                </div>
                <UiTextarea v-model="form.address" :label="t('appointments.places.address')" :maxlength="500" :rows="2" :error="errors.address" data-field="address" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiInput
                        v-model="form.slot_capacity"
                        type="number"
                        :min="String(limit('slot_capacity')[0])"
                        :max="String(limit('slot_capacity')[1])"
                        :label="t('appointments.places.slotCapacity')"
                        :hint="`${t(slotsMatter ? 'appointments.places.slotCapacityHint' : 'appointments.places.unusedNow')} ${range('slot_capacity')}`"
                        :error="errors.slot_capacity"
                        data-field="slot_capacity"
                    />
                    <UiInput
                        v-model="form.day_capacity"
                        type="number"
                        :min="String(limit('day_capacity')[0])"
                        :max="String(limit('day_capacity')[1])"
                        :label="t('appointments.places.dayCapacity')"
                        :hint="`${t(slotsMatter ? 'appointments.places.unusedNow' : 'appointments.places.dayCapacityHint')} ${range('day_capacity')}`"
                        :error="errors.day_capacity"
                        data-field="day_capacity"
                    />
                </div>

                <div class="space-y-3" data-field="services">
                    <UiRadioGroup
                        v-model="coverage"
                        :label="t('appointments.places.services')"
                        :options="[{ value: 'all', label: t('appointments.places.allServices'), description: t('appointments.places.allServicesHint') }, { value: 'some', label: t('appointments.places.someServices') }]"
                        :disabled="!canEdit || busy"
                    />
                    <div v-if="form.services !== null" class="grid gap-2 pl-7 sm:grid-cols-2">
                        <UiCheckbox
                            v-for="choice in serviceChoices"
                            :key="choice.key"
                            :model-value="form.services.includes(choice.key)"
                            :label="choice.name"
                            :description="choice.enabled ? undefined : t('appointments.places.serviceOff')"
                            :data-service-choice="choice.key"
                            @update:model-value="toggleService(choice.key, !!$event)"
                        />
                    </div>
                    <p v-if="error('services')" class="text-xs text-danger">{{ error('services') }}</p>
                </div>

                <div class="space-y-1.5">
                    <p class="text-sm font-medium text-fg">{{ t('appointments.places.week') }}</p>
                    <WeeklyHoursEditor v-model="form.hours" :weekdays="options.locations.weekdays" :periods-per-day="options.locations.periods_per_day" :errors="errors" :disabled="!canEdit || busy" />
                </div>

                <UiCheckbox v-model="form.is_active" :label="t('appointments.places.active')" :description="t('appointments.places.activeHint')" data-field="is_active" />
            </fieldset>
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ canEdit ? t('common.cancel') : t('common.close') }}</UiButton>
            <UiButton v-if="canEdit" type="submit" form="place-form" :loading="busy" :disabled="hasMistakes" data-save-place>{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
