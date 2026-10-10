<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, RotateCcw, ScanLine, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import { acceptFor, biometricDriver, CaptureError, type CaptureResult } from '@/lib/biometrics';
import { biometricLabel, type BiometricLabelKind } from '@/lib/biometrics/labels';
import { formatDateTime } from '@/lib/format';
import { http, toApiError } from '@/lib/http';
import { useConfirm } from '@/composables/useConfirm';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { BiometricCapture } from '@/types';

/**
 * One position of a person: what is stored for it, and capturing it with a driver the organization
 * allows. Low quality is handled here first (try again, then keep the best), so images the server
 * would refuse are not sent; the server checks the same rules.
 */
const props = defineProps<{ cardholderId: string; familyMember: string | null; modality: string; position: string; capture: BiometricCapture | null }>();
const emit = defineEmits<{ saved: []; 'image-expired': [] }>();
const { t, te } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const label = (kind: BiometricLabelKind, key: string) => biometricLabel(kind, key, t, te);
const settings = computed(() => auth.tenant!.biometrics!.modalities[props.modality]);
const options = computed(() => auth.tenant!.biometric_options!);
const device = computed(() => [props.capture?.device?.vendor, props.capture?.device?.model, props.capture?.device?.serial].filter(Boolean).join(' · '));
const canEnroll = computed(() => auth.can('biometrics.enroll'));

/* Driver: the organization's allowed ones in its order; one this browser build lacks is listed but cannot be picked. */
const PREFERENCE_KEY = `biometric-driver:${props.modality}`;
const driverChoices = computed(() =>
    settings.value.drivers
        .filter((name) => options.value.drivers[name]?.modalities.includes(props.modality))
        .map((name) => ({ name, available: !!biometricDriver(name) })),
);
function remembered(): string | null {
    try {
        return localStorage.getItem(PREFERENCE_KEY);
    } catch {
        return null;
    }
}
const driverName = ref(driverChoices.value.find((choice) => choice.available && choice.name === remembered())?.name ?? driverChoices.value.find((choice) => choice.available)?.name ?? '');
const driver = computed(() => (driverName.value ? biometricDriver(driverName.value) : undefined));
watch(driverName, (name) => {
    try {
        localStorage.setItem(PREFERENCE_KEY, name);
    } catch {
        // Only a convenience for this station
    }
});

const file = ref<File | null>(null);
const busy = ref(false);
const error = ref('');
/** Tries at this position since the panel opened, and the best result so far. */
const attempts = ref(0);
const result = ref<CaptureResult | null>(null);
const best = ref<CaptureResult | null>(null);
let abort: AbortController | null = null;

const isGood = (capture: CaptureResult) => capture.quality === null || capture.quality >= settings.value.min_quality;
const resultUrl = computed(() => {
    const type = result.value ? options.value.image_types[result.value.imageFormat] : undefined;

    return result.value && type?.displayable ? `data:${type.mime};base64,${result.value.imageBase64}` : null;
});
/** good: save; retry: below the minimum with tries left; keep: below the minimum, tries used up. */
const verdict = computed<'good' | 'retry' | 'keep' | null>(() => {
    if (!result.value) {
        return null;
    }
    if (isGood(result.value)) {
        return 'good';
    }

    return attempts.value < settings.value.max_attempts ? 'retry' : 'keep';
});

function captureMessage(caught: unknown): string {
    if (caught instanceof CaptureError) {
        return t(`biometrics.errors.${caught.code}`, caught.params);
    }

    return t('biometrics.errors.device');
}

async function takeCapture(): Promise<void> {
    if (!driver.value) {
        error.value = t('biometrics.errors.not_available');
        return;
    }
    busy.value = true;
    error.value = '';
    abort = new AbortController();
    try {
        const captured = await driver.value.capture({ modality: props.modality, position: props.position, options: options.value, file: file.value, signal: abort.signal });
        attempts.value++;
        result.value = captured;
        if (!best.value || (captured.quality ?? 101) > (best.value.quality ?? 101) || isGood(captured)) {
            best.value = captured;
        }
    } catch (caught) {
        if (!(caught instanceof CaptureError && caught.code === 'cancelled')) {
            error.value = captureMessage(caught);
        }
    } finally {
        busy.value = false;
        abort = null;
    }
}

async function save(keepLowQuality = false): Promise<void> {
    const chosen = keepLowQuality ? best.value : result.value;
    if (!chosen) {
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        await http.post(`/cardholders/${props.cardholderId}/biometrics`, {
            family_member: props.familyMember,
            modality: props.modality,
            position: props.position,
            driver: driverName.value,
            image_base64: chosen.imageBase64,
            image_format: chosen.imageFormat,
            template_base64: chosen.templateBase64 ?? null,
            template_format: chosen.templateFormat ?? null,
            quality: chosen.quality,
            attempts: Math.max(1, attempts.value),
            accept_low_quality: keepLowQuality,
            width: chosen.width ?? null,
            height: chosen.height ?? null,
            dpi: chosen.dpi ?? null,
            device: chosen.device ?? null,
        });
        ui.notify(t('biometrics.panel.saved'));
        reset();
        emit('saved');
    } catch (caught) {
        const apiError = toApiError(caught);
        error.value = Object.values(apiError.errors)[0]?.[0] ?? apiError.message;
    } finally {
        busy.value = false;
    }
}

function retake(): void {
    result.value = null;
    error.value = '';
}

/** After a save, a mark or a delete the panel shows what is stored (it stays open when no position is left). */
function reset(): void {
    retake();
    best.value = null;
    attempts.value = 0;
    file.value = null;
    marking.value = false;
    reason.value = '';
    note.value = '';
}

/* Not capturable (e.g. a missing finger), with the reason. */
const marking = ref(false);
const reason = ref('');
const note = ref('');
const noteNeeded = computed(() => options.value.unavailable_reasons_with_note.includes(reason.value));

async function markUnavailable(): Promise<void> {
    if (noteNeeded.value && !note.value.trim()) {
        error.value = t('biometrics.panel.noteNeeded');
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        await http.post(`/cardholders/${props.cardholderId}/biometrics/unavailable`, {
            family_member: props.familyMember,
            modality: props.modality,
            position: props.position,
            reason: reason.value,
            note: note.value.trim() || null,
        });
        ui.notify(t('biometrics.panel.markedUnavailable'));
        reset();
        emit('saved');
    } catch (caught) {
        const apiError = toApiError(caught);
        error.value = Object.values(apiError.errors)[0]?.[0] ?? apiError.message;
    } finally {
        busy.value = false;
    }
}

async function remove(): Promise<void> {
    const stored = props.capture!;
    if (!(await confirm({ title: t('biometrics.panel.deleteConfirm', { position: label('positions', props.position) }), message: t('biometrics.panel.deleteHint'), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    busy.value = true;
    try {
        await http.delete(`/cardholders/${props.cardholderId}/biometrics/${stored.id}`);
        ui.notify(t('common.deleted'));
        reset();
        emit('saved');
    } catch (caught) {
        ui.notify(toApiError(caught).message, 'error');
    } finally {
        busy.value = false;
    }
}

onBeforeUnmount(() => abort?.abort());
</script>

<template>
    <UiCard
        :title="label('positions', position)"
        :description="label('modalities', modality) !== label('positions', position) ? label('modalities', modality) : undefined"
        class="self-start lg:sticky lg:top-4"
        data-capture-panel
    >
        <div class="space-y-4">
            <!-- A new capture, not saved yet -->
            <template v-if="result">
                <div class="flex aspect-[4/5] items-center justify-center overflow-hidden rounded-lg border-2 border-dashed border-brand-ink bg-white">
                    <img v-if="resultUrl" :src="resultUrl" alt="" class="size-full object-contain" data-result-image />
                    <p v-else class="p-4 text-center text-sm text-muted">{{ t('biometrics.panel.notDisplayable', { format: result.imageFormat.toUpperCase() }) }}</p>
                </div>
                <div class="space-y-1">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted">{{ t('biometrics.panel.quality') }} · {{ t('biometrics.panel.attempt', { attempt: attempts, max: settings.max_attempts }) }}</span>
                        <span class="font-medium text-fg" data-result-quality>{{ result.quality === null ? t('biometrics.panel.qualityUnknown') : `${result.quality} / 100` }}</span>
                    </div>
                    <div v-if="result.quality !== null" class="relative h-2 rounded-full bg-surface-2">
                        <div class="h-2 rounded-full" :class="verdict === 'good' ? 'bg-success' : 'bg-warning'" :style="{ width: `${result.quality}%` }" />
                        <div class="absolute -top-1 h-4 w-0.5 bg-fg/60" :style="{ left: `${settings.min_quality}%` }" :title="t('biometrics.panel.minimum', { min: settings.min_quality })" />
                    </div>
                </div>
                <p v-if="verdict === 'retry'" class="rounded-lg bg-warning/10 p-3 text-sm text-fg" data-verdict="retry">
                    {{ t('biometrics.panel.retry', { quality: result.quality, min: settings.min_quality, left: settings.max_attempts - attempts }) }}
                </p>
                <p v-else-if="verdict === 'keep'" class="rounded-lg bg-warning/10 p-3 text-sm text-fg" data-verdict="keep">
                    {{ t('biometrics.panel.keep', { quality: best?.quality, min: settings.min_quality, tries: attempts }) }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <UiButton v-if="verdict === 'good'" :loading="busy" data-save @click="save()">{{ t('common.save') }}</UiButton>
                    <UiButton v-if="verdict === 'retry'" :loading="busy" data-try-again @click="takeCapture">{{ t('biometrics.panel.tryAgain') }}</UiButton>
                    <template v-if="verdict === 'keep'">
                        <UiButton :loading="busy" data-keep @click="save(true)">{{ t('biometrics.panel.keepBest', { quality: best?.quality }) }}</UiButton>
                        <UiButton variant="secondary" :disabled="busy" data-try-again @click="takeCapture">{{ t('biometrics.panel.tryAgain') }}</UiButton>
                    </template>
                    <UiButton variant="ghost" :disabled="busy" data-retake @click="retake"><RotateCcw class="size-4" />{{ t('biometrics.panel.discard') }}</UiButton>
                </div>
            </template>

            <!-- What is stored -->
            <template v-else-if="capture?.status === 'captured'">
                <div class="flex aspect-[4/5] items-center justify-center overflow-hidden rounded-lg border border-line bg-white">
                    <img v-if="capture.image_url" :src="capture.image_url" alt="" class="size-full object-contain" data-capture-image @error="$emit('image-expired')" />
                    <p v-else class="p-4 text-center text-sm text-muted">{{ t('biometrics.panel.notDisplayable', { format: capture.image_format?.toUpperCase() }) }}</p>
                </div>
                <div v-if="capture.quality !== null" class="space-y-1">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted">{{ t('biometrics.panel.quality') }}</span>
                        <span class="font-medium text-fg">{{ capture.quality }} / 100 <UiBadge v-if="capture.low_quality" tone="warning">{{ t('biometrics.panel.lowQuality') }}</UiBadge></span>
                    </div>
                    <div class="relative h-2 rounded-full bg-surface-2">
                        <div class="h-2 rounded-full" :class="capture.low_quality ? 'bg-warning' : 'bg-success'" :style="{ width: `${capture.quality}%` }" />
                        <div class="absolute -top-1 h-4 w-0.5 bg-fg/60" :style="{ left: `${settings.min_quality}%` }" :title="t('biometrics.panel.minimum', { min: settings.min_quality })" />
                    </div>
                </div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
                    <dt class="text-muted">{{ t('biometrics.panel.method') }}</dt>
                    <dd class="text-fg">{{ capture.driver ? label('drivers', capture.driver) : '—' }}<template v-if="device"> · {{ device }}</template></dd>
                    <dt class="text-muted">{{ t('biometrics.panel.image') }}</dt>
                    <dd class="text-fg">
                        {{ capture.image_format?.toUpperCase() }}<template v-if="capture.image_width"> · {{ capture.image_width }}×{{ capture.image_height }}</template><template v-if="capture.image_dpi"> · {{ capture.image_dpi }} dpi</template>
                    </dd>
                    <dt class="text-muted">{{ t('biometrics.panel.template') }}</dt>
                    <dd class="text-fg">{{ capture.has_template ? capture.template_format : t('biometrics.panel.noTemplate') }}</dd>
                    <dt class="text-muted">{{ t('biometrics.panel.attempts') }}</dt>
                    <dd class="text-fg">{{ capture.attempts }}</dd>
                    <dt class="text-muted">{{ t('biometrics.panel.capturedAt') }}</dt>
                    <dd class="text-fg">{{ formatDateTime(capture.captured_at) }}<template v-if="capture.captured_by"> · {{ capture.captured_by.name }}</template></dd>
                </dl>
            </template>
            <div v-else-if="capture?.status === 'unavailable'" class="space-y-2 rounded-lg bg-warning/10 p-3 text-sm" data-capture-unavailable>
                <p class="flex items-center gap-2 font-medium text-fg"><Ban class="size-4 text-warning" />{{ t('biometrics.panel.unavailable', { reason: label('reasons', capture.unavailable_reason ?? '') }) }}</p>
                <p v-if="capture.note" class="whitespace-pre-line text-muted">{{ capture.note }}</p>
                <p class="text-xs text-muted">{{ formatDateTime(capture.captured_at) }}<template v-if="capture.captured_by"> · {{ capture.captured_by.name }}</template></p>
            </div>
            <p v-else class="rounded-lg bg-surface-2 p-3 text-sm text-muted" data-capture-empty>{{ t('biometrics.panel.empty') }}</p>

            <!-- Capturing -->
            <div v-if="canEnroll && !result" class="space-y-3 border-t border-line pt-4" data-capture-controls>
                <UiSelect
                    v-model="driverName"
                    :label="t('biometrics.panel.driver')"
                    :options="driverChoices.map((choice) => ({ value: choice.name, label: choice.available ? label('drivers', choice.name) : t('biometrics.panel.driverMissing', { driver: label('drivers', choice.name) }) }))"
                    :placeholder="driverChoices.some((choice) => choice.available) ? undefined : t('biometrics.panel.noDriver')"
                    data-driver-select
                />
                <div v-if="driver?.needsFile" class="space-y-1.5">
                    <input
                        type="file"
                        :accept="acceptFor(modality, options)"
                        class="block w-full text-sm text-muted file:mr-3 file:h-10 file:rounded-lg file:border file:border-line file:bg-surface-2 file:px-3 file:text-fg"
                        data-capture-file
                        @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                    />
                    <p class="text-xs text-muted">{{ t('biometrics.panel.fileHint', { formats: options.modalities[modality].image_formats.map((format) => format.toUpperCase()).join(', '), kb: options.max_image_kb }) }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <UiButton :loading="busy" :disabled="!driver || (driver.needsFile && !file)" data-capture @click="takeCapture">
                        <ScanLine class="size-4" />{{ capture?.status === 'captured' ? t('biometrics.panel.captureAgain') : t('biometrics.panel.capture') }}
                    </UiButton>
                    <UiButton v-if="!marking" variant="secondary" :disabled="busy" data-mark-unavailable @click="marking = true">
                        <Ban class="size-4" />{{ t('biometrics.panel.cannotCapture') }}
                    </UiButton>
                </div>
                <div v-if="marking" class="space-y-3 rounded-lg border border-line p-3" data-unavailable-form>
                    <UiSelect
                        v-model="reason"
                        :label="t('biometrics.panel.reason')"
                        :placeholder="t('biometrics.panel.chooseReason')"
                        :options="options.unavailable_reasons.map((value) => ({ value, label: label('reasons', value) }))"
                        data-reason
                    />
                    <UiTextarea v-model="note" :label="t('biometrics.panel.note')" :required="noteNeeded" :maxlength="500" :rows="2" data-note />
                    <div class="flex gap-2">
                        <UiButton size="sm" :loading="busy" :disabled="!reason" data-confirm-unavailable @click="markUnavailable">{{ t('biometrics.panel.markUnavailable') }}</UiButton>
                        <UiButton size="sm" variant="ghost" :disabled="busy" @click="marking = false">{{ t('common.cancel') }}</UiButton>
                    </div>
                </div>
            </div>
            <p v-if="error" class="text-sm text-danger" data-capture-error>{{ error }}</p>
            <div v-if="capture && auth.can('biometrics.delete') && !result" class="border-t border-line pt-3">
                <button type="button" class="inline-flex items-center gap-1.5 text-sm text-muted hover:text-danger" :disabled="busy" data-delete-capture @click="remove">
                    <Trash2 class="size-4" />{{ t(capture.status === 'captured' ? 'biometrics.panel.delete' : 'biometrics.panel.deleteMark') }}
                </button>
            </div>
        </div>
    </UiCard>
</template>
