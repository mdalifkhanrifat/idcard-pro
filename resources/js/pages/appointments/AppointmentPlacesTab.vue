<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, ArrowDown, ArrowUp, CalendarSearch, MapPin, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import { useConfirm } from '@/composables/useConfirm';
import { formatNumber, localDigits } from '@/lib/format';
import { toApiError } from '@/lib/http';
import type { AppointmentOptions, AppointmentSettings } from '@/lib/appointments/api';
import { deletePlace, fetchPlaces, savePlace, type AppointmentPlace } from '@/lib/appointments/places';
import { serviceName } from '@/lib/appointments/services';
import AppointmentPlaceDialog from '@/pages/appointments/AppointmentPlaceDialog.vue';
import AppointmentPlacePreviewDialog from '@/pages/appointments/AppointmentPlacePreviewDialog.vue';
import { useUiStore } from '@/stores/ui';

/**
 * The places people come to (a branch, an office, a counter), each saved on its own (its own API — apart from
 * the page's save bar): the list in its order, added, changed, moved, turned off or deleted, and previewed as
 * people booking will see it. Warns while appointments are on and no place is. `unsaved`: the page has
 * settings not yet saved (the preview goes by the saved ones).
 */
const props = defineProps<{ options: AppointmentOptions; settings: AppointmentSettings; canEdit: boolean; unsaved: boolean }>();
const { t, locale } = useI18n();
const ui = useUiStore();
const { confirm } = useConfirm();

const places = ref<AppointmentPlace[]>([]);
const loading = ref(false);
const failed = ref(false);
const busy = ref(false);
const dialog = ref(false);
const editing = ref<AppointmentPlace | null>(null);
const previewing = ref<AppointmentPlace | null>(null);
const preview = ref(false);
function show(place: AppointmentPlace): void {
    previewing.value = place;
    preview.value = true;
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    try {
        places.value = await fetchPlaces();
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const noneActive = computed(() => props.settings.enabled && !loading.value && !failed.value && !places.value.some((place) => place.is_active));
const full = computed(() => places.value.length >= props.options.locations.max);
const nextOrder = computed(() => Math.max(0, ...places.value.map((place) => place.sort_order + 1)));

/** Open days with their periods, in short day names of the reader's language. */
const shortDay = (day: string) => new Intl.DateTimeFormat(locale.value === 'bn' ? 'bn-BD' : locale.value, { weekday: 'short' }).format(new Date(2024, 0, 1 + ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].indexOf(day)));
function week(place: AppointmentPlace): string {
    const open = props.options.locations.weekdays.filter((day) => place.hours[day]?.length);

    return open.length ? open.map((day) => `${shortDay(day)} ${place.hours[day].map(([from, to]) => `${localDigits(from)}–${localDigits(to)}`).join(', ')}`).join(' · ') : t('appointments.places.neverOpen');
}
function kinds(place: AppointmentPlace): string {
    return place.services === null ? t('appointments.places.allServices') : place.services.map((key) => (props.settings.services[key] ? serviceName(key, props.settings.services[key], props.options, locale.value) : key)).join(', ');
}

function edit(place: AppointmentPlace | null): void {
    editing.value = place;
    dialog.value = true;
}
function saved(place: AppointmentPlace): void {
    const at = places.value.findIndex((item) => item.id === place.id);
    places.value = at < 0 ? [...places.value, place] : places.value.map((item) => (item.id === place.id ? place : item));
}

/** Moves a place up or down; each place whose order changes is saved. */
async function move(index: number, step: -1 | 1): Promise<void> {
    const list = [...places.value];
    [list[index], list[index + step]] = [list[index + step], list[index]];
    busy.value = true;
    try {
        for (const [order, place] of list.entries()) {
            if (place.sort_order !== order) {
                list[order] = await savePlace(place.id, { sort_order: order });
            }
        }
        places.value = list;
    } catch (failure) {
        ui.notify(toApiError(failure).message, 'error');
        await load();
    } finally {
        busy.value = false;
    }
}

async function remove(place: AppointmentPlace): Promise<void> {
    if (!(await confirm({ title: t('appointments.places.deleteConfirm', { name: place.name }), message: t('appointments.places.deleteHint'), confirmLabel: t('appointments.places.delete'), danger: true }))) {
        return;
    }
    try {
        await deletePlace(place.id);
        places.value = places.value.filter((item) => item.id !== place.id);
        ui.notify(t('appointments.places.deleted'));
    } catch (failure) {
        ui.notify(toApiError(failure).message, 'error');
    }
}
</script>

<template>
    <div class="space-y-6">
        <p v-if="noneActive" class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/8 px-4 py-3 text-sm text-fg" data-no-active-place>
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />{{ t('appointments.places.noneActive') }}
        </p>

        <UiCard :title="t('appointments.places.title')" :description="t('appointments.places.description')" :padded="false" data-places-card>
            <div v-if="canEdit" class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3">
                <p class="text-xs text-muted">{{ t('appointments.places.count', { count: formatNumber(places.length), max: formatNumber(options.locations.max) }) }}</p>
                <UiButton size="sm" :disabled="full || loading" data-add-place @click="edit(null)"><Plus class="size-4" />{{ t('appointments.places.add') }}</UiButton>
            </div>
            <p v-if="loading && !places.length" class="py-10 text-center text-sm text-muted">{{ t('common.loading') }}</p>
            <UiEmpty v-else-if="failed" :title="t('appointments.places.loadFailed')" :hint="t('common.error')">
                <UiButton variant="secondary" @click="load">{{ t('appointments.retry') }}</UiButton>
            </UiEmpty>
            <UiEmpty v-else-if="!places.length" :title="t('appointments.places.empty')" :hint="t('appointments.places.emptyHint')" />
            <ul v-else class="divide-y divide-line" :class="busy && 'opacity-60'">
                <li v-for="(place, index) in places" :key="place.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start" :data-place="place.name">
                    <MapPin class="hidden size-5 shrink-0 text-muted sm:block" />
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="break-words font-medium text-fg" dir="auto">{{ place.name }}</span>
                            <span v-if="place.name_local" class="break-words text-sm text-muted" dir="auto">{{ place.name_local }}</span>
                            <UiBadge v-if="!place.is_active" tone="warning">{{ t('appointments.places.off') }}</UiBadge>
                        </p>
                        <p v-if="place.address" class="break-words text-sm text-muted" dir="auto">{{ place.address }}</p>
                        <p class="break-words text-xs text-muted" data-place-week>{{ week(place) }}</p>
                        <p class="break-words text-xs text-muted">
                            {{ settings.scheduling === 'slot' ? t('appointments.places.perSlot', { count: formatNumber(place.slot_capacity) }) : t('appointments.places.perDay', { count: formatNumber(place.day_capacity) }) }} · {{ kinds(place) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <template v-if="canEdit && places.length > 1">
                            <button type="button" class="rounded p-1.5 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-30" :disabled="busy || index === 0" :aria-label="t('settings.verification.up')" data-move-up @click="move(index, -1)"><ArrowUp class="size-4" /></button>
                            <button type="button" class="rounded p-1.5 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-30" :disabled="busy || index === places.length - 1" :aria-label="t('settings.verification.down')" data-move-down @click="move(index, 1)"><ArrowDown class="size-4" /></button>
                        </template>
                        <UiButton variant="ghost" size="sm" data-preview-place @click="show(place)"><CalendarSearch class="size-4" />{{ t('appointments.preview.show') }}</UiButton>
                        <UiButton variant="ghost" size="sm" data-edit-place @click="edit(place)"><Pencil class="size-4" />{{ canEdit ? t('appointments.places.edit') : t('appointments.places.view') }}</UiButton>
                        <UiButton v-if="canEdit" variant="ghost" size="sm" :aria-label="t('appointments.places.delete')" data-delete-place @click="remove(place)"><Trash2 class="size-4 text-danger" /></UiButton>
                    </div>
                </li>
            </ul>
        </UiCard>

        <AppointmentPlacePreviewDialog v-model:open="preview" :place="previewing" :options="options" :settings="settings" :unsaved="unsaved" />
        <AppointmentPlaceDialog v-model:open="dialog" :place="editing" :options="options" :settings="settings" :can-edit="canEdit" :next-order="nextOrder" @saved="saved" />
    </div>
</template>
