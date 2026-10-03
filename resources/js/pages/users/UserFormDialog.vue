<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { http, toApiError } from '@/lib/http';
import { useUiStore } from '@/stores/ui';
import type { User } from '@/types';

const props = defineProps<{ user: User | null; roles: string[] }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [] }>();
const { t } = useI18n();
const ui = useUiStore();

const blank = () => ({
    name: '', email: '', username: '', phone: '', locale: 'en', status: 'active',
    password: '', password_confirmation: '', roles: [] as string[], unlock: false,
});
const form = reactive(blank());
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const isEdit = computed(() => props.user !== null);

watch(open, (value) => {
    if (!value) {
        return;
    }
    errors.value = {};
    Object.assign(form, blank(), props.user
        ? { ...props.user, username: props.user.username ?? '', phone: props.user.phone ?? '', locale: props.user.locale ?? 'en', roles: [...(props.user.roles ?? [])] }
        : {});
});

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        const payload = { ...form, username: form.username || null, phone: form.phone || null, password: form.password || null };
        if (isEdit.value) {
            await http.put(`/users/${props.user!.id}`, payload);
        } else {
            await http.post('/users', payload);
        }
        ui.notify(t('common.saved'));
        open.value = false;
        emit('saved');
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
    <UiDialog v-model:open="open" :title="isEdit ? t('users.editTitle') : t('users.new')" size="lg">
        <form id="user-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <UiInput v-model="form.name" :label="t('users.name')" required :error="errors.name" class="sm:col-span-2" />
            <UiInput v-model="form.email" type="email" :label="t('users.email')" required :error="errors.email" />
            <UiInput v-model="form.username" :label="t('users.username')" :error="errors.username" />
            <UiInput v-model="form.phone" :label="t('users.phone')" inputmode="tel" :error="errors.phone" />
            <UiSelect v-model="form.locale" :label="t('common.language')" :options="[{ value: 'en', label: 'English' }, { value: 'bn', label: 'বাংলা' }]" />
            <UiSelect
                v-model="form.status"
                :label="t('common.status')"
                :options="[{ value: 'active', label: t('common.active') }, { value: 'inactive', label: t('common.inactive') }]"
                :error="errors.status"
            />
            <div class="sm:col-span-2">
                <p class="mb-2 text-sm font-medium text-fg">{{ t('users.roles') }}</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <UiCheckbox v-for="role in roles" :key="role" v-model="form.roles" :value="role" :label="role" />
                </div>
                <p v-if="errors.roles" class="mt-1 text-xs text-danger">{{ errors.roles[0] }}</p>
            </div>
            <UiInput
                v-model="form.password"
                type="password"
                :label="t('users.password')"
                autocomplete="new-password"
                :required="!isEdit"
                :hint="isEdit ? t('users.passwordOptional') : t('auth.passwordRules')"
                :error="errors.password"
            />
            <UiInput v-model="form.password_confirmation" type="password" :label="t('auth.confirmPassword')" autocomplete="new-password" :required="!isEdit" />
            <UiCheckbox v-if="isEdit && user?.is_locked" v-model="form.unlock" :label="t('users.unlock')" class="sm:col-span-2" />
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="user-form" :loading="busy">{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
