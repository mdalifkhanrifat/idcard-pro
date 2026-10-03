<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { BadgeCheck, Upload } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { applyBrand } from '@/lib/brand';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { Tenant } from '@/types';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();

const form = reactive({ name: '', legal_name: '', email: '', phone: '', country_code: '', timezone: 'UTC', locale: 'en', primary_color: '#0f62fe' });
const logo = ref<File | null>(null);
const logoPreview = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const canEdit = computed(() => auth.can('settings.update'));
const timezones = Intl.supportedValuesOf('timeZone').map((z) => ({ value: z, label: z }));

onMounted(async () => {
    const { data } = await http.get<{ data: Tenant }>('/settings');
    Object.assign(form, {
        ...data.data,
        legal_name: data.data.legal_name ?? '', email: data.data.email ?? '', phone: data.data.phone ?? '', country_code: data.data.country_code ?? '',
    });
    logoPreview.value = data.data.logo_url;
});

watch(() => form.primary_color, (color) => applyBrand(color));

function pickLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    logo.value = file;
    logoPreview.value = file ? URL.createObjectURL(file) : logoPreview.value;
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};
    const body = new FormData();
    Object.entries(form).forEach(([k, v]) => body.append(k, v ?? ''));
    if (logo.value) {
        body.append('logo', logo.value);
    }
    try {
        await http.post('/settings', body);
        await auth.fetchSession();
        ui.notify(t('common.saved'));
    } catch (error) {
        errors.value = toApiError(error).errors;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('settings.title')" :subtitle="t('settings.subtitle', { org: auth.tenant?.name })">
            <UiButton v-if="canEdit" :loading="busy" @click="save">{{ t('common.save') }}</UiButton>
        </UiPageHeader>

        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
            <div class="space-y-6">
                <UiCard :title="t('settings.branding')">
                    <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-2">
                        <UiInput v-model="form.name" :label="t('settings.name')" required :error="errors.name" />
                        <UiInput v-model="form.legal_name" :label="t('settings.legalName')" :error="errors.legal_name" />
                        <UiInput v-model="form.email" type="email" :label="t('settings.email')" :error="errors.email" />
                        <UiInput v-model="form.phone" :label="t('settings.phone')" :error="errors.phone" />
                        <div class="space-y-1.5">
                            <label class="block text-sm font-medium text-fg">{{ t('settings.brandColor') }}</label>
                            <div class="flex items-center gap-2">
                                <input v-model="form.primary_color" type="color" class="h-10 w-14 cursor-pointer rounded-lg border border-line bg-surface p-1" />
                                <input v-model="form.primary_color" class="h-10 flex-1 rounded-lg border border-line bg-surface px-3 font-mono text-sm uppercase" maxlength="7" />
                            </div>
                            <p v-if="errors.primary_color" class="text-xs text-danger">{{ errors.primary_color[0] }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <span class="block text-sm font-medium text-fg">{{ t('settings.logo') }}</span>
                            <label class="flex h-10 cursor-pointer items-center gap-2 rounded-lg border border-dashed border-line px-3 text-sm text-muted hover:border-brand hover:text-fg">
                                <Upload class="size-4" />{{ logo?.name ?? t('settings.logoHint') }}
                                <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="pickLogo" />
                            </label>
                            <p v-if="errors.logo" class="text-xs text-danger">{{ errors.logo[0] }}</p>
                        </div>
                    </fieldset>
                </UiCard>
                <UiCard :title="t('settings.regional')">
                    <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-3">
                        <UiInput v-model="form.country_code" :label="t('settings.country')" :maxlength="2" :error="errors.country_code" />
                        <UiSelect v-model="form.timezone" :label="t('settings.timezone')" :options="timezones" :error="errors.timezone" />
                        <UiSelect v-model="form.locale" :label="t('settings.locale')" :options="[{ value: 'en', label: 'English' }, { value: 'bn', label: 'বাংলা' }]" />
                    </fieldset>
                </UiCard>
            </div>

            <UiCard :title="t('settings.preview')">
                <div class="overflow-hidden rounded-xl border border-line shadow-sm">
                    <div class="flex items-center gap-3 px-4 py-3 text-brand-contrast" style="background: var(--brand)">
                        <img v-if="logoPreview" :src="logoPreview" class="size-8 rounded bg-white/90 object-contain p-0.5" alt="" />
                        <BadgeCheck v-else class="size-6" />
                        <span class="truncate font-semibold">{{ form.name }}</span>
                    </div>
                    <div class="flex gap-3 bg-white p-4 text-slate-900">
                        <div class="h-20 w-16 rounded bg-slate-200" />
                        <div class="space-y-1.5 pt-1 text-xs">
                            <p class="font-semibold">Jane Doe</p>
                            <p class="text-slate-500">Senior Engineer</p>
                            <p class="font-mono text-slate-500">EMP/26000142</p>
                        </div>
                    </div>
                </div>
            </UiCard>
        </div>
    </div>
</template>
