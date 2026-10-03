<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import type { CustomField } from '@/types';

/**
 * Inputs for a set of custom fields; values are keyed by field key.
 */
defineProps<{ groups: { name: string; fields: CustomField[] }[]; label: (field: CustomField) => string; errors: Record<string, string[]> }>();
const values = defineModel<Record<string, string | boolean>>({ required: true });
const { t } = useI18n();
</script>

<template>
    <div class="space-y-8">
        <section v-for="group in groups" :key="group.name">
            <h3 v-if="group.name" class="mb-3 text-sm font-semibold text-fg">{{ group.name }}</h3>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <template v-for="field in group.fields" :key="field.key">
                    <UiSelect
                        v-if="field.type === 'select'"
                        v-model="values[field.key] as string"
                        :label="label(field)"
                        placeholder="—"
                        :required="field.is_required"
                        :options="(field.options ?? []).map((option) => ({ value: option, label: option }))"
                        :error="errors[`custom_fields.${field.key}`]"
                    />
                    <UiTextarea
                        v-else-if="field.type === 'textarea'"
                        v-model="values[field.key] as string"
                        :label="label(field)"
                        :required="field.is_required"
                        :error="errors[`custom_fields.${field.key}`]"
                        class="sm:col-span-2 lg:col-span-3"
                    />
                    <div v-else-if="field.type === 'checkbox'" class="flex items-end pb-2">
                        <UiCheckbox v-model="values[field.key] as boolean" :label="label(field)" />
                    </div>
                    <UiInput
                        v-else
                        v-model="values[field.key] as string"
                        :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'"
                        :label="label(field)"
                        :required="field.is_required"
                        :error="errors[`custom_fields.${field.key}`]"
                    />
                </template>
            </div>
        </section>
        <p v-if="errors.custom_fields" class="text-sm text-danger">{{ errors.custom_fields[0] }}</p>
        <p v-if="!groups.length" class="text-sm text-muted">{{ t('customFields.none') }}</p>
    </div>
</template>
