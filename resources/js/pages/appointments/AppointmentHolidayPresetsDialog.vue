<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import { formatNumber } from '@/lib/format';
import { toApiError } from '@/lib/http';
import { addHolidayPresets, closureDates, type HolidayPreset } from '@/lib/appointments/closures';
import { useUiStore } from '@/stores/ui';

/**
 * The country's public holidays on a fixed date, to add at once as yearly closures of every place: all those not
 * there yet chosen at first; those already there shown, not offered again.
 */
const props = defineProps<{ presets: HolidayPreset[] }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ added: [] }>();
const { t, locale } = useI18n();
const ui = useUiStore();

const chosen = ref<string[]>([]);
const busy = ref(false);
watch(open, (value) => value && (chosen.value = props.presets.filter((preset) => !preset.added).map((preset) => preset.key)));

const offered = computed(() => props.presets.filter((preset) => !preset.added));
const day = (preset: HolidayPreset) => closureDates({ date_from: `2000-${preset.date}`, date_to: `2000-${preset.date}`, yearly: true }, locale.value);
function toggle(key: string, on: boolean): void {
    chosen.value = on ? [...chosen.value.filter((item) => item !== key), key] : chosen.value.filter((item) => item !== key);
}

async function add(): Promise<void> {
    busy.value = true;
    try {
        const result = await addHolidayPresets(chosen.value);
        ui.notify(t('appointments.holidays.added', { count: formatNumber(result.added) }));
        open.value = false;
        emit('added');
    } catch (failure) {
        ui.notify(toApiError(failure).message, 'error');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <UiDialog v-model:open="open" :title="t('appointments.holidays.title')" :description="t('appointments.holidays.description')" size="md" full-on-phone>
        <div class="space-y-3" data-holiday-presets>
            <p v-if="!offered.length" class="rounded-lg border border-line px-4 py-6 text-center text-sm text-muted" data-holidays-all-added>{{ t('appointments.holidays.allAdded') }}</p>
            <ul class="divide-y divide-line rounded-lg border border-line">
                <li v-for="preset in presets" :key="preset.key" class="flex items-start justify-between gap-3 px-4 py-3" :data-holiday="preset.key">
                    <UiCheckbox
                        :model-value="preset.added || chosen.includes(preset.key)"
                        :disabled="preset.added || busy"
                        :label="preset.name"
                        :description="day(preset)"
                        @update:model-value="toggle(preset.key, !!$event)"
                    />
                    <UiBadge v-if="preset.added" tone="success" class="shrink-0">{{ t('appointments.holidays.there') }}</UiBadge>
                </li>
            </ul>
            <p class="text-xs text-muted">{{ t('appointments.holidays.hint') }}</p>
        </div>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton :loading="busy" :disabled="!chosen.length" data-add-holidays @click="add">{{ t('appointments.holidays.add', { count: formatNumber(chosen.length) }) }}</UiButton>
        </template>
    </UiDialog>
</template>
