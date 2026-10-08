<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { useI18n } from 'vue-i18n';
import { ArrowDown, ArrowUp, BadgeCheck, Upload } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { applyBrand } from '@/lib/brand';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import { SAMPLE_VERIFY_CODE, verificationFieldLabel, verifyUrlFor } from '@/pages/cards/cards';
import type { Tenant, TenantVerification } from '@/types';

const { t, te } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();

const form = reactive({ name: '', legal_name: '', email: '', phone: '', country_code: '', timezone: 'UTC', locale: 'en', primary_color: '#0f62fe', card_number_format: '{CODE}/{YY}{SEQ:6}', card_number_on_reissue: 'keep' });
/** Landscape default size of new card templates in mm (legacy Template Setup). */
const cardSize = reactive({ width: 85.6 as number | string, height: 54 as number | string });
/** Public card verification (3.7): on or off, what the page shows (in order), own link address and a note. */
const verification = reactive<TenantVerification>({ enabled: false, fields: [], show_revoke_reason: false, link: null, note: null, log_checks: true });
const verificationFields = ref<string[]>([]);
const sampleLink = computed(() => verifyUrlFor({ verification } as Tenant, SAMPLE_VERIFY_CODE, true));
const fieldLabel = (key: string) => verificationFieldLabel(key, t, te);

function toggleField(key: string, on: boolean): void {
    verification.fields = on ? [...verification.fields, key] : verification.fields.filter((field) => field !== key);
}

function moveField(index: number, step: -1 | 1): void {
    const fields = [...verification.fields];
    [fields[index], fields[index + step]] = [fields[index + step], fields[index]];
    verification.fields = fields;
}

const sampleCode = ref('EMP');
const numberPreview = ref('');
const previewError = ref('');
const logo = ref<File | null>(null);
const logoPreview = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const canEdit = computed(() => auth.can('settings.update'));
const origin = window.location.origin;
const timezones = Intl.supportedValuesOf('timeZone').map((z) => ({ value: z, label: z }));

onMounted(async () => {
    const { data } = await http.get<{ data: Tenant }>('/settings');
    // Only the form's own keys: the rest of the organization record is not sent back
    for (const key of Object.keys(form) as (keyof typeof form)[]) {
        form[key] = (data.data[key as keyof Tenant] as string | null | undefined) ?? '';
    }
    Object.assign(verification, data.data.verification ?? {});
    verificationFields.value = data.data.verification_fields ?? [];
    logoPreview.value = data.data.logo_url;
    Object.assign(cardSize, data.data.default_card_size ?? {});
});

watch(() => form.primary_color, (color) => applyBrand(color));

// Ask the server so the preview uses the real next sequence value.
watchDebounced(
    [() => form.card_number_format, sampleCode],
    async () => {
        try {
            const { data } = await http.get('/settings/card-number-preview', { params: { format: form.card_number_format, code: sampleCode.value || 'EMP' } });
            numberPreview.value = data.data.number;
            previewError.value = '';
        } catch (error) {
            const apiError = toApiError(error);
            previewError.value = apiError.errors.format?.[0] ?? apiError.errors.code?.[0] ?? apiError.message;
        }
    },
    { debounce: 300, immediate: true },
);

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
    body.append('default_card_size[width]', String(cardSize.width));
    body.append('default_card_size[height]', String(cardSize.height));
    body.append('verification[enabled]', verification.enabled ? '1' : '0');
    verification.fields.forEach((field) => body.append('verification[fields][]', field));
    body.append('verification[show_revoke_reason]', verification.show_revoke_reason ? '1' : '0');
    body.append('verification[link]', verification.link?.trim() ?? '');
    body.append('verification[note]', verification.note?.trim() ?? '');
    body.append('verification[log_checks]', verification.log_checks ? '1' : '0');
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
                            <label class="flex h-10 cursor-pointer items-center gap-2 rounded-lg border border-dashed border-line px-3 text-sm text-muted hover:border-brand-ink hover:text-fg">
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
                <UiCard :title="t('settings.cardNumber.title')" :description="t('settings.cardNumber.description')">
                    <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                        <UiInput v-model="form.card_number_format" :label="t('settings.cardNumber.format')" :maxlength="60" :error="errors.card_number_format ?? previewError" />
                        <UiInput v-model="sampleCode" :label="t('settings.cardNumber.sampleCode')" :maxlength="20" />
                    </fieldset>
                    <p class="mt-3 text-sm text-muted">
                        {{ t('settings.cardNumber.next') }}
                        <span class="ml-1 rounded-md bg-surface-2 px-2 py-1 font-mono text-fg">{{ previewError ? '—' : numberPreview }}</span>
                    </p>
                    <p class="mt-3 text-xs text-muted">{{ t('settings.cardNumber.tokens') }}</p>
                    <fieldset :disabled="!canEdit" class="mt-4 border-t border-line pt-4">
                        <UiSelect
                            v-model="form.card_number_on_reissue"
                            :label="t('settings.cardNumber.onReissue')"
                            :options="(['keep', 'new'] as const).map((value) => ({ value, label: t(`settings.cardNumber.reissue.${value}`) }))"
                            :error="errors.card_number_on_reissue"
                            data-setting="card_number_on_reissue"
                        />
                        <p class="mt-1.5 text-xs text-muted">{{ t(`settings.cardNumber.reissueHint.${form.card_number_on_reissue}`) }}</p>
                    </fieldset>
                </UiCard>
                <UiCard :title="t('settings.cardSize.title')" :description="t('settings.cardSize.description')">
                    <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-3">
                        <UiInput v-model="cardSize.width" type="number" step="any" :label="t('settings.cardSize.width')" :error="errors['default_card_size.width']" />
                        <UiInput v-model="cardSize.height" type="number" step="any" :label="t('settings.cardSize.height')" :error="errors['default_card_size.height']" />
                        <div class="flex items-end pb-2">
                            <button type="button" class="text-sm text-brand-ink hover:underline" data-cr80 @click="Object.assign(cardSize, { width: 85.6, height: 54 })">{{ t('templates.useCr80') }}</button>
                        </div>
                    </fieldset>
                </UiCard>
                <UiCard :title="t('settings.verification.title')" :description="t('settings.verification.description')" data-verification-settings>
                    <fieldset :disabled="!canEdit" class="space-y-4">
                        <UiCheckbox v-model="verification.enabled" :label="t('settings.verification.enabled')" :description="t('settings.verification.enabledHint')" data-setting="verification.enabled" />
                        <template v-if="verification.enabled">
                            <div class="space-y-2">
                                <p class="text-sm font-medium text-fg">{{ t('settings.verification.fields') }}</p>
                                <div class="grid gap-2 sm:grid-cols-3">
                                    <UiCheckbox
                                        v-for="key in verificationFields"
                                        :key="key"
                                        :model-value="verification.fields.includes(key)"
                                        :label="fieldLabel(key)"
                                        :data-verification-field="key"
                                        @update:model-value="toggleField(key, !!$event)"
                                    />
                                </div>
                                <p class="text-xs text-muted">{{ t('settings.verification.fieldsHint') }}</p>
                                <p v-if="errors['verification.fields']" class="text-xs text-danger">{{ errors['verification.fields'][0] }}</p>
                            </div>
                            <div v-if="verification.fields.length > 1" class="space-y-1.5">
                                <p class="text-sm font-medium text-fg">{{ t('settings.verification.order') }}</p>
                                <ol class="divide-y divide-line rounded-lg border border-line text-sm" data-verification-order>
                                    <li v-for="(key, index) in verification.fields" :key="key" class="flex items-center gap-2 px-3 py-1.5">
                                        <span class="w-5 text-xs text-muted">{{ index + 1 }}</span>
                                        <span class="flex-1 text-fg">{{ fieldLabel(key) }}</span>
                                        <button type="button" class="rounded p-1 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-30" :disabled="index === 0" :aria-label="t('settings.verification.up')" @click="moveField(index, -1)"><ArrowUp class="size-4" /></button>
                                        <button type="button" class="rounded p-1 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-30" :disabled="index === verification.fields.length - 1" :aria-label="t('settings.verification.down')" @click="moveField(index, 1)"><ArrowDown class="size-4" /></button>
                                    </li>
                                </ol>
                            </div>
                            <UiCheckbox v-model="verification.show_revoke_reason" :label="t('settings.verification.revokeReason')" :description="t('settings.verification.revokeReasonHint')" data-setting="verification.show_revoke_reason" />
                            <div class="space-y-1.5">
                                <UiInput v-model="verification.link" :label="t('settings.verification.link')" :placeholder="`${origin}/v/{code}`" :maxlength="200" :error="errors['verification.link']" data-setting="verification.link" />
                                <p class="text-xs text-muted">{{ t('settings.verification.linkHint') }}</p>
                                <p class="text-xs text-muted">{{ t('settings.verification.sample') }} <span class="break-all font-mono text-fg" data-verification-sample>{{ sampleLink }}</span></p>
                            </div>
                            <UiCheckbox v-model="verification.log_checks" :label="t('settings.verification.logChecks')" :description="t('settings.verification.logChecksHint')" data-setting="verification.log_checks" />
                            <UiTextarea v-model="verification.note" :label="t('settings.verification.note')" :placeholder="t('settings.verification.notePlaceholder')" :maxlength="500" :rows="2" :error="errors['verification.note']" />
                            <p class="rounded-lg bg-surface-2 p-3 text-xs text-muted">{{ t('settings.verification.qrHint') }}</p>
                        </template>
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
