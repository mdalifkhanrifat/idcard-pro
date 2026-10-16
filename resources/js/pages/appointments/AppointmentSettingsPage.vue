<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { Eye } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import PageTabs from '@/components/PageTabs.vue';
import SettingsSaveBar from '@/components/SettingsSaveBar.vue';
import { useSettingsDraft } from '@/composables/useSettingsDraft';
import { useUrlTab } from '@/composables/useUrlTab';
import { fetchAppointmentSettings, saveAppointmentSettings, type AppointmentSettings, type AppointmentSettingsResponse } from '@/lib/appointments/api';
import AppointmentGeneralTab from '@/pages/appointments/AppointmentGeneralTab.vue';
import { useAuthStore } from '@/stores/auth';

/**
 * The organization's appointments (5.3): whether they are used and how. Seen by those who see appointments or
 * may change the settings; changed with the settings (settings.update). The kinds of visit, places and closures
 * come as tabs of their own.
 */
const { t } = useI18n();
const auth = useAuthStore();
const canEdit = computed(() => auth.can('settings.update'));

type TabKey = 'general';
const TABS = computed<TabKey[]>(() => ['general']);
const tab = useUrlTab(TABS);

const { data, draft, loading, failed, saving, errors, dirty, load, save, discard } = useSettingsDraft<AppointmentSettingsResponse, AppointmentSettings>({
    fetch: fetchAppointmentSettings,
    store: saveAppointmentSettings,
    settingsOf: (response) => response.appointments,
    leaveHint: () => t('appointments.save.leaveHint'),
});
onMounted(load);
</script>

<template>
    <div>
        <UiPageHeader :title="t('nav.appointmentSettings')" :subtitle="t('appointments.subtitle')" />

        <p v-if="!canEdit" class="mb-4 flex items-start gap-2 rounded-lg border border-line bg-surface-2 px-4 py-3 text-sm text-muted" data-read-only>
            <Eye class="mt-0.5 size-4 shrink-0" />{{ t('appointments.readOnly') }}
        </p>

        <PageTabs v-model="tab" :tabs="TABS" :label="(key) => t(`appointments.tabs.${key}`)" />

        <p v-if="loading && !data" class="py-10 text-center text-sm text-muted">{{ t('common.loading') }}</p>
        <UiCard v-else-if="failed" :padded="false">
            <UiEmpty :title="t('appointments.loadFailed')" :hint="t('common.error')">
                <UiButton variant="secondary" :loading="loading" @click="load">{{ t('appointments.retry') }}</UiButton>
            </UiEmpty>
        </UiCard>

        <div v-else-if="data && draft && tab === 'general'" :class="dirty && canEdit && 'pb-20'" data-tab="general">
            <AppointmentGeneralTab v-model="draft" :options="data.options" :errors="errors" :disabled="!canEdit || saving" />
        </div>

        <SettingsSaveBar v-if="dirty && canEdit" :saving="saving" @save="save" @discard="discard" />
    </div>
</template>
