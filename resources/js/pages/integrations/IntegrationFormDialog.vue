<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { http, toApiError } from '@/lib/http';
import type { Integration } from '@/lib/integrations/api';
import { addressError, parseAddresses } from '@/lib/integrations/tokens';
import { useUiStore } from '@/stores/ui';

const props = defineProps<{ account: Integration | null; roles: string[] }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [account: Integration, isNew: boolean] }>();
const { t } = useI18n();
const ui = useUiStore();

const blank = () => ({ name: '', description: '', role: '', status: 'active', addresses: '' });
const form = reactive(blank());
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const isEdit = computed(() => props.account !== null);
/** An account stronger than this person: its words can be corrected, nothing else (the server's rule). */
const beyondReach = computed(() => props.account !== null && !props.roles.includes(props.account.role ?? ''));
const roleOptions = computed(() => {
    const names = props.account?.role && !props.roles.includes(props.account.role) ? [props.account.role, ...props.roles] : props.roles;

    return names.map((name) => ({ value: name, label: name }));
});

watch(open, (value) => {
    if (!value) {
        return;
    }
    errors.value = {};
    Object.assign(form, blank(), props.account
        ? { name: props.account.name, description: props.account.description ?? '', role: props.account.role ?? '', status: props.account.status, addresses: props.account.allowed_ips.join('\n') }
        : {});
});

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    const words = { name: form.name, description: form.description || null };
    try {
        const { data } = isEdit.value
            ? await http.patch(`/integrations/${props.account!.id}`, beyondReach.value ? words : { ...words, role: form.role, status: form.status, allowed_ips: parseAddresses(form.addresses) })
            : await http.post('/integrations', { ...words, role: form.role, status: form.status, allowed_ips: parseAddresses(form.addresses) });
        ui.notify(t('common.saved'));
        open.value = false;
        emit('saved', data.data, !isEdit.value);
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.errors;
        if (!Object.keys(apiError.errors).length) {
            ui.notify(apiError.message, 'error');
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <UiDialog v-model:open="open" :title="isEdit ? t('integrations.editTitle') : t('integrations.new')" size="lg" full-on-phone>
        <form id="integration-form" class="grid gap-4 sm:grid-cols-2" data-integration-form @submit.prevent="submit">
            <UiInput v-model="form.name" :label="t('integrations.name')" :hint="t('integrations.nameHint')" required :error="errors.name" class="sm:col-span-2" />
            <UiTextarea v-model="form.description" :label="t('integrations.description')" :rows="2" :error="errors.description" class="sm:col-span-2" />
            <template v-if="!beyondReach">
                <UiSelect v-model="form.role" :label="t('integrations.role')" :placeholder="t('integrations.chooseRole')" :options="roleOptions" required :error="errors.role" />
                <UiSelect
                    v-model="form.status"
                    :label="t('common.status')"
                    :options="[{ value: 'active', label: t('common.active') }, { value: 'inactive', label: t('common.inactive') }]"
                    :error="errors.status"
                />
                <UiTextarea
                    v-model="form.addresses"
                    :label="t('integrations.addresses')"
                    :hint="t('integrations.addressesHint')"
                    :rows="3"
                    dir="ltr"
                    :error="addressError(errors)"
                    class="sm:col-span-2"
                />
            </template>
            <p v-else class="text-sm text-muted sm:col-span-2" data-integration-beyond>{{ t('integrations.beyondReach') }}</p>
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="integration-form" :loading="busy">{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
