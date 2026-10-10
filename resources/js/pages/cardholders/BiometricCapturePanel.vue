<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiCard from '@/components/ui/UiCard.vue';
import { biometricLabel, type BiometricLabelKind } from '@/lib/biometrics/labels';
import { formatDateTime } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import type { BiometricCapture } from '@/types';

/** One position of a person: what is stored for it, and (4.6গ) capturing it. */
const props = defineProps<{ cardholderId: string; familyMember: string | null; modality: string; position: string; capture: BiometricCapture | null }>();
defineEmits<{ saved: []; 'image-expired': [] }>();
const { t, te } = useI18n();
const auth = useAuthStore();

const label = (kind: BiometricLabelKind, key: string) => biometricLabel(kind, key, t, te);
const settings = computed(() => auth.tenant!.biometrics!.modalities[props.modality]);
const device = computed(() => [props.capture?.device?.vendor, props.capture?.device?.model, props.capture?.device?.serial].filter(Boolean).join(' · '));
</script>

<template>
    <UiCard :title="label('positions', position)" :description="label('modalities', modality) !== label('positions', position) ? label('modalities', modality) : undefined" class="self-start lg:sticky lg:top-4" data-capture-panel>
        <div class="space-y-4">
            <template v-if="capture?.status === 'captured'">
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
        </div>
    </UiCard>
</template>
