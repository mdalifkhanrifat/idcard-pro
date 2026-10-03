import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { http } from '@/lib/http';
import { formatDate } from '@/lib/format';
import type { CustomField } from '@/types';

/**
 * Active custom field definitions of an entity, with helpers for labels, grouping and display.
 */
export function useCustomFields(entity = 'cardholder') {
    const { t, locale } = useI18n();
    const fields = ref<CustomField[]>([]);

    async function load(): Promise<void> {
        fields.value = (await http.get('/custom-fields', { params: { entity, active: 1 } })).data.data;
    }

    function label(field: CustomField): string {
        return locale.value === 'bn' && field.label_local ? field.label_local : field.label;
    }

    const groups = computed(() => {
        const result = new Map<string, CustomField[]>();
        for (const field of fields.value) {
            const name = field.group ?? '';
            result.set(name, [...(result.get(name) ?? []), field]);
        }

        return [...result.entries()].map(([name, items]) => ({ name, fields: items }));
    });

    function display(field: CustomField, value: unknown): string | null {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        if (field.type === 'checkbox') {
            return value ? t('common.yes') : t('common.no');
        }

        return field.type === 'date' ? formatDate(String(value)) : String(value);
    }

    return { fields, groups, load, label, display };
}
