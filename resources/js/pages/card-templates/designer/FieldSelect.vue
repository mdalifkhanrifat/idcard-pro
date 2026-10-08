<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { DesignerCatalog } from '@/types';

/**
 * Data field picker grouped like the legacy relation select (personal / family / student → custom fields),
 * plus the card's own fields.
 */
defineProps<{ catalog: DesignerCatalog; fieldLabel: (field: string) => string; invalid?: boolean }>();
const model = defineModel<string | null | undefined>();
const { t } = useI18n();
</script>

<template>
    <select
        v-model="model"
        class="h-8 w-full rounded-md border bg-surface px-2 text-sm text-fg focus:border-brand-ink focus:ring-2 focus:ring-[var(--ring)] focus:outline-none"
        :class="invalid ? 'border-danger' : 'border-line'"
    >
        <option :value="null" disabled>{{ t('designer.chooseField') }}</option>
        <optgroup v-for="(fields, group) in catalog.fields" :key="group" :label="t(`designer.fieldGroups.${group}`)">
            <option v-for="field in fields" :key="field" :value="field">{{ fieldLabel(field) }}</option>
        </optgroup>
        <optgroup v-if="catalog.custom_fields.length" :label="t('designer.fieldGroups.custom')">
            <option v-for="field in catalog.custom_fields" :key="field.key" :value="field.key">{{ field.label }}</option>
        </optgroup>
    </select>
</template>
