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
import { withRemovedServices } from '@/lib/appointments/services';
import AppointmentGeneralTab from '@/pages/appointments/AppointmentGeneralTab.vue';
import AppointmentPlacesTab from '@/pages/appointments/AppointmentPlacesTab.vue';
import AppointmentServicesTab from '@/pages/appointments/AppointmentServicesTab.vue';
import { useAuthStore } from '@/stores/auth';

/**
 * The organization's appointments (5.3): whether they are used and how. Seen by those who see appointments or
 * may change the settings; changed with the settings (settings.update). The kinds of visit, places and closures
 * come as tabs of their own.
 */
const { t } = useI18n();
const auth = useAuthStore();
const canEdit = computed(() => auth.can('settings.update'));

type TabKey = 'general' | 'services' | 'places';
const TABS = computed<TabKey[]>(() => ['general', 'services', 'places']);
const tab = useUrlTab(TABS);
/** Where a refused save's mistakes are: the kinds of visit have a tab of their own. */
const tabOf = (field: string): TabKey => (field.startsWith('appointments.services') ? 'services' : 'general');
const marked = computed(() => [...new Set(Object.keys(errors.value).map(tabOf))]);

const { data, draft, loading, failed, saving, errors, dirty, load, save, discard } = useSettingsDraft<AppointmentSettingsResponse, AppointmentSettings>({
    fetch: fetchAppointmentSettings,
    // A kind of visit removed from the draft goes as null
    store: (changes, saved) => saveAppointmentSettings(saved ? withRemovedServices(changes, saved) : changes),
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

        <PageTabs v-model="tab" :tabs="TABS" :label="(key) => t(`appointments.tabs.${key}`)" :marked="marked" />

        <p v-if="loading && !data" class="py-10 text-center text-sm text-muted">{{ t('common.loading') }}</p>
        <UiCard v-else-if="failed" :padded="false">
            <UiEmpty :title="t('appointments.loadFailed')" :hint="t('common.error')">
                <UiButton variant="secondary" :loading="loading" @click="load">{{ t('appointments.retry') }}</UiButton>
            </UiEmpty>
        </UiCard>

        <div v-else-if="data && draft" :class="dirty && canEdit && 'pb-20'" :data-tab="tab">
            <AppointmentGeneralTab v-if="tab === 'general'" v-model="draft" :options="data.options" :errors="errors" :disabled="!canEdit || saving" />
            <AppointmentServicesTab v-else-if="tab === 'services'" v-model="draft" :options="data.options" :errors="errors" :disabled="!canEdit || saving" />
            <!-- Saved on their own; named by the saved settings -->
            <AppointmentPlacesTab v-else-if="tab === 'places'" :options="data.options" :settings="data.appointments" :can-edit="canEdit" />
        </div>

        <SettingsSaveBar v-if="dirty && canEdit" :saving="saving" @save="save" @discard="discard" />
    </div>
</template>
