<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Plus, Trash2 } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiRadioGroup from '@/components/ui/UiRadioGroup.vue';
import type { EventOptionDescription, EventOptionValue } from '@/lib/notifications/api';
import { nextNumber } from '@/lib/notifications/options';

/**
 * An event's own settings (5.7d), drawn from the server's description alone — a choice as radio buttons, a list of
 * numbers as rows to add and remove — so a new setting of any event needs nothing here. Changes go to the draft.
 */
const props = defineProps<{ eventKey: string; options: EventOptionDescription[]; errors: Record<string, string[]>; disabled?: boolean }>();
const values = defineModel<Record<string, EventOptionValue>>({ required: true });
const { t } = useI18n();

const error = (path: string) => props.errors[`notifications.events.${props.eventKey}.options.${path}`];
const numbers = (key: string): number[] => (Array.isArray(values.value[key]) ? (values.value[key] as number[]) : []);
function setNumber(key: string, index: number, value: number | string | null | undefined): void {
    values.value[key] = numbers(key).map((number, at) => (at === index ? Number(value) : number));
}
function addNumber(option: EventOptionDescription): void {
    values.value[option.key] = [...numbers(option.key), nextNumber(numbers(option.key), option)];
}
</script>

<template>
    <fieldset :disabled="disabled" class="space-y-4 rounded-lg border border-line bg-surface-2 p-4" :data-event-options="eventKey">
        <div v-for="option in options" :key="option.key" :data-event-option="`${eventKey}.${option.key}`">
            <UiRadioGroup
                v-if="option.kind === 'choice'"
                :model-value="String(values[option.key] ?? option.default)"
                @update:model-value="values[option.key] = $event"
                :label="option.label"
                :hint="option.hint"
                :error="error(option.key)"
                :disabled="disabled"
                :options="(option.choices ?? []).map((choice) => ({ value: choice.key, label: choice.name }))"
            />
            <div v-else class="space-y-2">
                <p class="text-sm font-medium text-fg">{{ option.label }}</p>
                <div v-for="(number, index) in numbers(option.key)" :key="index" class="flex items-end gap-2" data-option-number>
                    <div class="w-40">
                        <UiInput
                            :model-value="number"
                            type="number"
                            :min="String(option.min)"
                            :max="String(option.max)"
                            :label="option.unit"
                            :error="error(`${option.key}.${index}`)"
                            @update:model-value="setNumber(option.key, index, $event)"
                        />
                    </div>
                    <UiButton
                        v-if="!disabled && numbers(option.key).length > 1"
                        variant="ghost"
                        size="sm"
                        :aria-label="t('notifications.events.options.remove')"
                        class="mb-0.5"
                        @click="values[option.key] = numbers(option.key).filter((_, at) => at !== index)"
                        ><Trash2 class="size-4"
                    /></UiButton>
                </div>
                <p class="text-xs break-words" :class="error(option.key) ? 'text-danger' : 'text-muted'">{{ error(option.key)?.[0] ?? option.hint }}</p>
                <UiButton v-if="!disabled && numbers(option.key).length < (option.max_items ?? 1)" variant="secondary" size="sm" data-add-option-number @click="addNumber(option)"
                    ><Plus class="size-4" />{{ t('notifications.events.options.add') }}</UiButton
                >
            </div>
        </div>
    </fieldset>
</template>
