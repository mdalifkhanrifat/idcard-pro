<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ArrowRight, Building2, Plus } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiPagination from '@/components/ui/UiPagination.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { useListQuery } from '@/composables/useListQuery';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { Tenant } from '@/types';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const router = useRouter();

const list = useListQuery<Tenant>('/platform/tenants', { filters: { name: '', status: '' } });
const { filters, rows, meta, page } = list;
onMounted(() => list.load());

const open = ref(false);
const form = reactive({ name: '', slug: '', country_code: 'BD', timezone: 'Asia/Dhaka', locale: 'en', owner: { name: '', email: '', password: '' } });
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

async function create(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        await http.post('/platform/tenants', form);
        open.value = false;
        ui.notify(t('common.saved'));
        list.load();
    } catch (error) {
        errors.value = toApiError(error).errors;
    } finally {
        busy.value = false;
    }
}

async function toggleStatus(tenant: Tenant): Promise<void> {
    await http.put(`/platform/tenants/${tenant.id}`, { status: tenant.status === 'active' ? 'suspended' : 'active' });
    list.load();
}

async function enter(tenant: Tenant): Promise<void> {
    await auth.actAs(tenant.slug);
    router.push('/');
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('platform.title')" :subtitle="t('platform.subtitle')">
            <UiButton @click="open = true"><Plus class="size-4" />{{ t('platform.new') }}</UiButton>
        </UiPageHeader>

        <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_200px]">
            <UiInput v-model="filters.name" :placeholder="t('common.search')" />
            <UiSelect v-model="filters.status" :placeholder="t('common.all')" :options="[{ value: 'active', label: t('common.active') }, { value: 'suspended', label: t('common.suspended') }]" />
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="tenant in rows" :key="tenant.id" class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 items-center justify-center rounded-lg text-white" :style="{ background: tenant.primary_color }"><Building2 class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate font-semibold text-fg">{{ tenant.name }}</h3>
                        <p class="font-mono text-xs text-muted">{{ tenant.slug }}</p>
                    </div>
                    <UiBadge :tone="tenant.status === 'active' ? 'success' : 'danger'">{{ t(`common.${tenant.status}`) }}</UiBadge>
                </div>
                <p class="mt-4 text-sm text-muted">{{ t('platform.users') }}: <span class="font-medium text-fg">{{ tenant.users_count }}</span></p>
                <div class="mt-4 flex gap-2 border-t border-line pt-4">
                    <UiButton size="sm" :disabled="tenant.status !== 'active'" @click="enter(tenant)">{{ t('platform.actAs') }}<ArrowRight class="size-3.5" /></UiButton>
                    <UiButton size="sm" variant="ghost" @click="toggleStatus(tenant)">{{ tenant.status === 'active' ? t('platform.suspend') : t('platform.activate') }}</UiButton>
                </div>
            </article>
        </div>
        <div class="mt-4 rounded-xl border border-line bg-surface">
            <UiPagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" :from="meta.from" :to="meta.to" @change="page = $event" />
        </div>

        <UiDialog v-model:open="open" :title="t('platform.new')" size="lg">
            <form id="tenant-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="create">
                <UiInput v-model="form.name" :label="t('settings.name')" required :error="errors.name" />
                <UiInput v-model="form.slug" :label="t('platform.slug')" :hint="t('platform.slugHint')" required :error="errors.slug" />
                <UiInput v-model="form.country_code" :label="t('settings.country')" :maxlength="2" />
                <UiInput v-model="form.timezone" :label="t('settings.timezone')" :error="errors.timezone" />
                <p class="pt-2 text-sm font-semibold text-fg sm:col-span-2">{{ t('platform.owner') }}</p>
                <UiInput v-model="form.owner.name" :label="t('users.name')" required :error="errors['owner.name']" />
                <UiInput v-model="form.owner.email" type="email" :label="t('users.email')" required :error="errors['owner.email']" />
                <UiInput v-model="form.owner.password" type="password" :label="t('auth.password')" :hint="t('auth.passwordRules')" required autocomplete="new-password" :error="errors['owner.password']" class="sm:col-span-2" />
            </form>
            <template #footer>
                <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
                <UiButton type="submit" form="tenant-form" :loading="busy">{{ t('common.create') }}</UiButton>
            </template>
        </UiDialog>
    </div>
</template>
