<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { formatNumber } from '@/lib/format';
import type { AppointmentOptions, AppointmentSettings } from '@/lib/appointments/api';
import { serviceKeyFrom, serviceName } from '@/lib/appointments/services';

/**
 * The kinds of visit people book: the registry's (renamed, turned off, never removed) and the organization's own
 * (added and removed). Each: on or off, its name in every language (empty: the default name, shown greyed), the
 * time slots it takes and whether the public link offers it. Names of languages and defaults come from the server.
 */
const props = defineProps<{ options: AppointmentOptions; errors: Record<string, string[]>; disabled?: boolean }>();
const settings = defineModel<AppointmentSettings>({ required: true });
const { t, locale } = useI18n();

const error = (field: string) => Object.entries(props.errors).find(([key]) => key === `appointments.${field}` || key.startsWith(`appointments.${field}.`))?.[1];
const languageName = (language: string) => props.options.labels.languages[language] ?? language;
const [minSlots, maxSlots] = props.options.limits.service_slots ?? [1, 8];
const own = computed(() => Object.values(settings.value.services).filter((service) => service.custom).length);
const canAdd = computed(() => own.value < props.options.custom_services.max);
const slotsMatter = computed(() => settings.value.scheduling === 'slot');
const publicLink = computed(() => settings.value.booking.includes('public'));

function setSlots(key: string, value: number | string | null | undefined): void {
    settings.value.services[key].slots = Number(value);
}
function remove(key: string): void {
    const { [key]: _removed, ...rest } = settings.value.services;
    settings.value.services = rest;
}

/** A new kind of visit: its names first; its key follows from them. */
const newNames = ref<Record<string, string>>(Object.fromEntries(props.options.languages.map((language) => [language, ''])));
const newKey = computed(() => serviceKeyFrom(props.options.languages.map((language) => newNames.value[language].trim()), Object.keys(settings.value.services), props.options.custom_services.key));
const canCreate = computed(() => Object.values(newNames.value).some((name) => name.trim() !== ''));
function add(): void {
    settings.value.services = {
        ...settings.value.services,
        [newKey.value]: { enabled: true, custom: true, name: Object.fromEntries(props.options.languages.map((language) => [language, newNames.value[language].trim() || null])), slots: 1, public: true },
    };
    newNames.value = Object.fromEntries(props.options.languages.map((language) => [language, '']));
}
</script>

<template>
    <div class="space-y-6">
        <UiCard :title="t('appointments.services.title')" :description="t('appointments.services.description')" data-services-card>
            <p v-if="errors['appointments.services']" class="mb-4 text-sm text-danger" data-error="services">{{ errors['appointments.services'][0] }}</p>
            <fieldset :disabled="disabled">
                <ul class="divide-y divide-line">
                    <li v-for="(service, key) in settings.services" :key="key" class="space-y-4 py-5 first:pt-0 last:pb-0" :data-service="key">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="min-w-0 break-words font-medium text-fg">{{ serviceName(String(key), service, options, locale) }}</p>
                            <UiBadge :tone="service.custom ? 'brand' : 'neutral'">{{ service.custom ? t('appointments.services.own') : t('appointments.services.builtIn') }}</UiBadge>
                            <UiBadge v-if="!service.enabled" tone="warning">{{ t('appointments.services.off') }}</UiBadge>
                            <span class="font-mono text-xs text-muted" :title="t('appointments.services.keyHint')">{{ key }}</span>
                            <UiButton v-if="service.custom" variant="ghost" size="sm" class="ml-auto" :aria-label="t('appointments.services.remove')" data-remove-service @click="remove(String(key))"><Trash2 class="size-4" /></UiButton>
                        </div>

                        <UiCheckbox v-model="service.enabled" :label="t('appointments.services.enabled')" data-setting="enabled" />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <UiInput
                                v-for="language in options.languages"
                                :key="language"
                                :model-value="service.name[language]"
                                :label="t('appointments.services.name', { language: languageName(language) })"
                                :placeholder="options.services[key]?.names[language] ?? ''"
                                :maxlength="options.limits.name?.[1]"
                                :error="error(`services.${key}.name.${language}`) ?? (language === options.languages[0] ? error(`services.${key}.name`) : undefined)"
                                :data-name="language"
                                dir="auto"
                                @update:model-value="service.name[language] = String($event ?? '') || null"
                            />
                        </div>
                        <p v-if="!service.custom" class="-mt-2 text-xs text-muted">{{ t('appointments.services.defaultHint') }}</p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <UiInput
                                    :model-value="service.slots"
                                    type="number"
                                    :min="String(minSlots)"
                                    :max="String(maxSlots)"
                                    :label="t('appointments.services.slots')"
                                    :hint="slotsMatter ? t('appointments.services.slotsHint', { minutes: formatNumber(service.slots * settings.slot_minutes) }) : t('appointments.services.slotsDay')"
                                    :error="error(`services.${key}.slots`)"
                                    data-setting="slots"
                                    @update:model-value="setSlots(String(key), $event)"
                                />
                            </div>
                            <div class="sm:pt-7">
                                <UiCheckbox
                                    v-model="service.public"
                                    :label="t('appointments.services.public')"
                                    :description="publicLink ? t('appointments.services.publicHint') : t('appointments.services.publicOff')"
                                    data-setting="public"
                                />
                            </div>
                        </div>
                    </li>
                </ul>
            </fieldset>
        </UiCard>

        <UiCard :title="t('appointments.services.addTitle')" :description="t('appointments.services.addDescription', { max: formatNumber(options.custom_services.max) })" data-add-service-card>
            <p v-if="!canAdd" class="text-sm text-muted" data-add-full>{{ t('appointments.services.full', { max: formatNumber(options.custom_services.max) }) }}</p>
            <fieldset v-else :disabled="disabled" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiInput v-for="language in options.languages" :key="language" v-model="newNames[language]" :label="t('appointments.services.name', { language: languageName(language) })" :maxlength="options.limits.name?.[1]" :data-new-name="language" dir="auto" />
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <UiButton variant="secondary" :disabled="!canCreate" data-add-service @click="add"><Plus class="size-4" />{{ t('appointments.services.add') }}</UiButton>
                    <span v-if="canCreate" class="text-xs text-muted" data-new-key>{{ t('appointments.services.newKey', { key: newKey }) }}</span>
                </div>
            </fieldset>
        </UiCard>
    </div>
</template>
