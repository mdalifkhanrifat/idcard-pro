<script setup lang="ts">
import { formatNumber } from '@/lib/format';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, Loader2, ScanLine } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiCard from '@/components/ui/UiCard.vue';
import BiometricCapturePanel from './BiometricCapturePanel.vue';
import { biometricLabel, positionRows, type BiometricLabelKind } from '@/lib/biometrics/labels';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { BiometricCapture, BiometricStatus, FamilyMember } from '@/types';

/**
 * Biometric enrollment of a cardholder and, when the organization captures them, their family members
 * (Phase 4). Kinds, positions, counts and drivers all come from the organization's settings and the
 * registry in the session (`tenant.biometrics`, `tenant.biometric_options`).
 */
const props = defineProps<{ cardholderId: string }>();
const { t, te } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();

const settings = computed(() => auth.tenant!.biometrics!);
const options = computed(() => auth.tenant!.biometric_options!);
const label = (kind: BiometricLabelKind, key: string) => biometricLabel(kind, key, t, te);

const members = ref<FamilyMember[]>([]);
/** Whose biometrics: null = the cardholder, else a family member's id. */
const person = ref<string | null>(null);
const captures = ref<BiometricCapture[]>([]);
const status = ref<BiometricStatus | null>(null);
const loading = ref(true);
const selected = ref<{ modality: string; position: string } | null>(null);

const modalities = computed(() => Object.keys(options.value.modalities).filter((name) => status.value?.modalities[name] && settings.value.modalities[name]?.mode !== 'off'));
const captureAt = (modality: string, position: string) => captures.value.find((capture) => capture.modality === modality && capture.position === position) ?? null;

/** With stand-ins any position may count, so all are offered; otherwise only the chosen ones. */
function positionsOf(modality: string): string[] {
    const chosen = settings.value.modalities[modality];

    return chosen.substitutes ? options.value.modalities[modality].positions : chosen.positions;
}

function preference(modality: string, position: string): number {
    return settings.value.modalities[modality].positions.indexOf(position) + 1;
}

function modalityTone(modality: string): 'success' | 'warning' | 'neutral' {
    const state = status.value!.modalities[modality];
    if (state.complete) {
        return state.with_exceptions ? 'warning' : 'success';
    }

    return state.required ? 'warning' : 'neutral';
}

function modalityState(modality: string): string {
    const state = status.value!.modalities[modality];
    if (state.complete) {
        return t(state.with_exceptions ? 'biometrics.tab.completeWithExceptions' : 'biometrics.tab.complete');
    }

    return t(state.required ? 'biometrics.tab.incomplete' : 'biometrics.tab.optionalOpen');
}

/** Only the latest answer counts (the person may change while an earlier list is on its way). */
let latestLoad = 0;
async function load(): Promise<void> {
    const request = ++latestLoad;
    loading.value = true;
    try {
        const { data } = await http.get(`/cardholders/${props.cardholderId}/biometrics`, { params: person.value ? { family_member: person.value } : {} });
        if (request !== latestLoad) {
            return;
        }
        captures.value = data.data;
        status.value = data.meta.status;
        // Start where work is left: the first position still needed
        if (!selected.value || !modalities.value.includes(selected.value.modality)) {
            const first = modalities.value.find((name) => status.value!.modalities[name].next.length > 0) ?? modalities.value[0];
            selected.value = first ? { modality: first, position: status.value!.modalities[first].next[0] ?? positionsOf(first)[0] } : null;
        }
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    } finally {
        if (request === latestLoad) {
            loading.value = false;
        }
    }
}

/** Image links are short-lived: when one has run out, fetch fresh ones (at most once a minute). */
let refreshedAt = 0;
function imageExpired(): void {
    if (Date.now() - refreshedAt > 60_000) {
        refreshedAt = Date.now();
        void load();
    }
}

/** After a save: the panel moves on to the next position still needed, if any. */
async function saved(): Promise<void> {
    const current = selected.value;
    await load();
    if (!current || !status.value) {
        return;
    }
    // The same kind first, then any other kind with positions still needed
    const modality = [current.modality, ...modalities.value].find((name) => (status.value!.modalities[name]?.next.length ?? 0) > 0);
    if (modality) {
        selected.value = { modality, position: status.value.modalities[modality].next[0] };
    }
}

/** On narrow screens the panel sits below the positions: bring it into view when one is picked. */
async function pick(modality: string, position: string): Promise<void> {
    selected.value = { modality, position };
    if (window.matchMedia('(max-width: 1023px)').matches) {
        await nextTick();
        document.querySelector('[data-capture-panel]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

watch(person, () => {
    selected.value = null;
    void load();
});

onMounted(async () => {
    if (settings.value.family_members) {
        members.value = (await http.get(`/cardholders/${props.cardholderId}/family-members`)).data.data;
    }
    await load();
});
</script>

<template>
    <div class="space-y-5" data-biometrics-tab>
        <div v-if="members.length" class="flex gap-1 overflow-x-auto" role="tablist" :aria-label="t('biometrics.tab.person')">
            <button
                v-for="option in [{ id: null, name: t('biometrics.tab.cardholder') }, ...members.map((member) => ({ id: member.id, name: `${member.name} · ${t(`cardholders.family.relations.${member.relation}`)}` }))]"
                :key="option.id ?? 'cardholder'"
                type="button"
                role="tab"
                :aria-selected="person === option.id"
                class="rounded-full border px-3 py-1 text-sm whitespace-nowrap"
                :class="person === option.id ? 'border-brand-ink bg-brand/12 text-brand-ink' : 'border-line text-muted hover:text-fg'"
                :data-person="option.id ?? 'cardholder'"
                @click="person = option.id"
            >
                {{ option.name }}
            </button>
        </div>

        <div v-if="loading && !status" class="flex justify-center py-16 text-muted"><Loader2 class="size-6 animate-spin" /></div>
        <p v-else-if="status && !status.applies" class="rounded-lg bg-surface-2 p-4 text-sm text-muted">{{ t('biometrics.tab.notForFamily') }}</p>
        <div v-else-if="status" class="grid gap-6 lg:grid-cols-[1fr_340px]">
            <div class="space-y-5">
                <p class="flex items-center gap-2 text-sm" data-biometric-overall>
                    <UiBadge :tone="status.complete ? 'success' : 'warning'">{{ t(status.complete ? 'biometrics.tab.allComplete' : 'biometrics.tab.notComplete') }}</UiBadge>
                    <span class="text-muted">{{ t(status.complete ? 'biometrics.tab.allCompleteHint' : 'biometrics.tab.notCompleteHint') }}</span>
                </p>
                <UiCard v-for="modality in modalities" :key="modality" :data-biometric-modality="modality">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-fg">
                            {{ label('modalities', modality) }}
                            <UiBadge>{{ label('modes', status.modalities[modality].mode) }}</UiBadge>
                        </h3>
                        <span class="flex items-center gap-2 text-sm text-muted">
                            {{ t('biometrics.tab.counted', { counted: formatNumber(status.modalities[modality].counted), needed: formatNumber(status.modalities[modality].needed) }) }}
                            <UiBadge :tone="modalityTone(modality)" data-modality-state>{{ modalityState(modality) }}</UiBadge>
                        </span>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div v-for="row in positionRows(positionsOf(modality), options.modalities[modality].groups)" :key="row.group ?? ''" class="space-y-1.5">
                            <p v-if="row.group" class="text-xs font-medium text-muted">{{ label('groups', row.group) }}</p>
                            <div class="grid grid-cols-5 gap-1.5 sm:gap-2">
                                <button
                                    v-for="position in row.positions"
                                    :key="position"
                                    type="button"
                                    class="group relative flex min-w-0 flex-col items-center gap-1 rounded-lg border p-1 text-center transition sm:p-1.5"
                                    :class="[
                                        selected?.modality === modality && selected.position === position ? 'border-brand-ink ring-2 ring-[var(--ring)]' : 'border-line hover:border-brand-ink',
                                        status.modalities[modality].next.includes(position) && 'border-dashed border-brand-ink',
                                    ]"
                                    :aria-pressed="selected?.modality === modality && selected.position === position"
                                    :data-position="position"
                                    :data-state="captureAt(modality, position)?.status ?? (status.modalities[modality].next.includes(position) ? 'next' : 'empty')"
                                    @click="pick(modality, position)"
                                >
                                    <span class="flex aspect-[4/5] w-full items-center justify-center overflow-hidden rounded bg-surface-2 text-muted">
                                        <img v-if="captureAt(modality, position)?.image_url" :src="captureAt(modality, position)!.image_url!" alt="" class="size-full object-contain" @error="imageExpired" />
                                        <Ban v-else-if="captureAt(modality, position)?.status === 'unavailable'" class="size-5 text-warning" />
                                        <span v-else-if="captureAt(modality, position)?.status === 'captured'" class="text-[10px] font-semibold text-fg">{{ captureAt(modality, position)!.image_format?.toUpperCase() }}</span>
                                        <ScanLine v-else class="size-5 opacity-40" />
                                    </span>
                                    <span class="line-clamp-2 text-[10px] leading-tight break-words text-fg sm:text-[11px]">{{ label('positions', position) }}</span>
                                    <span v-if="preference(modality, position)" class="absolute top-1 left-1 flex size-4 items-center justify-center rounded-full bg-brand text-[10px] font-semibold text-brand-contrast" :title="t('biometrics.tab.preferred')">{{ preference(modality, position) }}</span>
                                    <span v-if="captureAt(modality, position)?.status === 'captured' && captureAt(modality, position)!.quality !== null" class="absolute top-1 right-1 rounded px-1 text-[10px] font-semibold" :class="captureAt(modality, position)!.low_quality ? 'bg-warning text-white' : 'bg-success text-white'">{{ captureAt(modality, position)!.quality }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-muted">{{ t('biometrics.tab.legend') }}</p>
                </UiCard>
            </div>

            <BiometricCapturePanel
                v-if="selected"
                :key="`${person}:${selected.modality}:${selected.position}`"
                :cardholder-id="cardholderId"
                :family-member="person"
                :modality="selected.modality"
                :position="selected.position"
                :capture="captureAt(selected.modality, selected.position)"
                @saved="saved"
                @image-expired="imageExpired"
            />
        </div>
    </div>
</template>
