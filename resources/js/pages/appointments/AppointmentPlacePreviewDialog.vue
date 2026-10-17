<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Info } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiRadioGroup from '@/components/ui/UiRadioGroup.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import type { AppointmentOptions, AppointmentSettings } from '@/lib/appointments/api';
import type { AppointmentPlace } from '@/lib/appointments/places';
import { serviceName } from '@/lib/appointments/services';
import AvailabilityWeek from '@/pages/appointments/AvailabilityWeek.vue';

/**
 * How a place will look to whoever books: a week of its days for a kind of visit (AvailabilityWeek) for staff or
 * as the public link shows it. By the saved settings, as they will be once appointments and the place are on
 * (a preview), on the organization's wall clock.
 */
const props = defineProps<{ place: AppointmentPlace | null; options: AppointmentOptions; settings: AppointmentSettings; unsaved: boolean }>();
const open = defineModel<boolean>('open', { default: false });
const { t, locale } = useI18n();

/** The kinds of visit the place takes that are on. */
const services = computed(() =>
    Object.entries(props.settings.services)
        .filter(([key, service]) => service.enabled && (!props.place?.services || props.place.services.includes(key)))
        .map(([key, service]) => ({ value: key, label: serviceName(key, service, props.options, locale.value) })),
);
const audiences = computed(() => props.settings.booking.map((key) => ({ value: key, label: props.options.labels.booking[key] ?? key })));

const service = ref('');
const audience = ref('staff');

watch(open, (value) => {
    if (!value) {
        return;
    }
    service.value = services.value.find((choice) => choice.value === service.value)?.value ?? services.value[0]?.value ?? '';
    audience.value = props.settings.booking.includes(audience.value) ? audience.value : (props.settings.booking[0] ?? 'staff');
}, { immediate: true });
</script>

<template>
    <UiDialog v-model:open="open" :title="t('appointments.preview.title', { name: place?.name ?? '' })" :description="t('appointments.preview.description')" size="xl" full-on-phone>
        <div class="space-y-4" data-preview>
            <div class="space-y-2 text-xs">
                <p v-if="!settings.enabled || (place && !place.is_active)" class="flex items-start gap-2 rounded-lg border border-line bg-surface-2 px-3 py-2 text-muted" data-preview-off>
                    <Info class="mt-0.5 size-4 shrink-0" />{{ !settings.enabled ? t('appointments.preview.moduleOff') : t('appointments.preview.placeOff') }}
                </p>
                <p v-if="unsaved" class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/8 px-3 py-2 text-fg" data-preview-unsaved>
                    <Info class="mt-0.5 size-4 shrink-0 text-warning" />{{ t('appointments.preview.unsaved') }}
                </p>
            </div>

            <p v-if="!services.length" class="rounded-lg border border-line px-4 py-6 text-center text-sm text-muted" data-preview-no-service>{{ t('appointments.preview.noService') }}</p>
            <template v-else>
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiSelect v-model="service" :label="t('appointments.preview.service')" :options="services" data-preview-service />
                    <UiRadioGroup v-if="audiences.length > 1" v-model="audience" :label="t('appointments.preview.audience')" :options="audiences" data-preview-audience />
                </div>

                <AvailabilityWeek :place-id="place?.id ?? null" :service="service" :audience="audience" preview :days-ahead="settings.days_ahead" data-preview-days />
            </template>
        </div>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.close') }}</UiButton>
        </template>
    </UiDialog>
</template>
