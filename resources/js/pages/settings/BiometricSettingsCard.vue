<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiCard from '@/components/ui/UiCard.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { biometricLabel, positionRows, type BiometricLabelKind } from '@/lib/biometrics/labels';
import { formatNumber } from '@/lib/format';
import type { BiometricModalitySettings, BiometricOptions, TenantBiometrics } from '@/types';

/**
 * The organization's biometric settings (Phase 4). Every kind, position, driver, mode and moment comes
 * from the registry (`biometric_options`), so a kind added there appears here without a code change.
 */
const props = defineProps<{ options: BiometricOptions; errors: Record<string, string[]>; disabled?: boolean }>();
const settings = defineModel<TenantBiometrics>({ required: true });
const { t, te } = useI18n();

const label = (kind: BiometricLabelKind, key: string) => biometricLabel(kind, key, t, te);
const modalities = computed(() => Object.keys(props.options.modalities).filter((name) => settings.value.modalities[name]));
const printableKinds = computed(() => Object.entries(props.options.modalities).filter(([, modality]) => modality.printable).map(([name]) => label('modalities', name)));

const modality = (name: string): BiometricModalitySettings => settings.value.modalities[name];
const offered = (name: string): string[] => props.options.modalities[name].positions;

/** Count in the reader's digits (e.g. ১০ in Bangla). */
const number = formatNumber;

/** An error for a part not on screen (biometrics off, or a kind turned off) is shown on what is. */
const hiddenError = (prefix: string): string | undefined => {
    const match = Object.keys(props.errors).find((field) => field.startsWith(prefix));

    return match ? props.errors[match][0] : undefined;
};

/** First error for a field or anything inside it (e.g. positions.1). */
function error(path: string): string | undefined {
    const key = `biometrics.${path}`;
    const match = Object.keys(props.errors).find((field) => field === key || field.startsWith(`${key}.`));

    return match ? props.errors[match][0] : undefined;
}

/** Without stand-ins only the chosen positions count, so no more than those can be asked for. */
function maxCount(name: string): number {
    return modality(name).substitutes ? offered(name).length : Math.max(1, modality(name).positions.length);
}

function clampCount(name: string): void {
    modality(name).count = Math.min(Math.max(1, Math.round(Number(modality(name).count)) || 1), maxCount(name));
}

function setMode(name: string, mode: string): void {
    modality(name).mode = mode;
    // A kind with a single position (e.g. face) has nothing to choose
    if (mode !== 'off' && modality(name).positions.length === 0 && offered(name).length === 1) {
        modality(name).positions = [...offered(name)];
    }
}

function togglePosition(name: string, position: string): void {
    const positions = modality(name).positions;
    modality(name).positions = positions.includes(position) ? positions.filter((item) => item !== position) : [...positions, position];
    clampCount(name);
}

function movePosition(name: string, index: number, step: -1 | 1): void {
    const positions = [...modality(name).positions];
    [positions[index], positions[index + step]] = [positions[index + step], positions[index]];
    modality(name).positions = positions;
}

function toggleDriver(name: string, driver: string, on: boolean): void {
    // The organization's order is kept; a newly allowed driver goes last
    const drivers = modality(name).drivers.filter((item) => item !== driver);
    modality(name).drivers = on ? [...drivers, driver] : drivers;
}

function toggleSubstitutes(name: string, on: boolean): void {
    modality(name).substitutes = on;
    clampCount(name);
}

function toggleMoment(moment: string, on: boolean): void {
    settings.value.required_before = props.options.required_before.filter((item) => (item === moment ? on : settings.value.required_before.includes(item)));
}
</script>

<template>
    <UiCard :title="t('settings.biometrics.title')" :description="t('settings.biometrics.description')" data-biometric-settings>
        <fieldset :disabled="disabled" class="space-y-4">
            <UiCheckbox v-model="settings.enabled" :label="t('settings.biometrics.enabled')" :description="t('settings.biometrics.enabledHint')" data-setting="biometrics.enabled" />
            <p v-if="!settings.enabled && hiddenError('biometrics')" class="text-xs text-danger" data-biometric-error>{{ hiddenError('biometrics') }}</p>
            <template v-if="settings.enabled">
                <p v-if="errors['biometrics.modalities']" class="text-xs text-danger" data-biometric-error>{{ errors['biometrics.modalities'][0] }}</p>

                <section v-for="name in modalities" :key="name" class="space-y-4 rounded-lg border border-line p-4" :data-biometric-modality="name">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-fg">{{ label('modalities', name) }}</h3>
                        <div role="radiogroup" :aria-label="t('settings.biometrics.mode')" class="inline-flex rounded-lg border border-line p-0.5">
                            <button
                                v-for="mode in options.modes"
                                :key="mode"
                                type="button"
                                role="radio"
                                :aria-checked="modality(name).mode === mode"
                                class="rounded-md px-3 py-1 text-sm whitespace-nowrap transition"
                                :class="modality(name).mode === mode ? 'bg-brand text-brand-contrast' : 'text-muted hover:text-fg'"
                                :data-mode="mode"
                                @click="setMode(name, mode)"
                            >
                                {{ label('modes', mode) }}
                            </button>
                        </div>
                    </div>
                    <p v-if="te(`settings.biometrics.modeHint.${modality(name).mode}`)" class="-mt-2 text-xs text-muted">{{ t(`settings.biometrics.modeHint.${modality(name).mode}`) }}</p>
                    <p v-if="modality(name).mode === 'off' && hiddenError(`biometrics.modalities.${name}.`)" class="text-xs text-danger" data-biometric-error>{{ hiddenError(`biometrics.modalities.${name}.`) }}</p>

                    <template v-if="modality(name).mode !== 'off'">
                        <div v-if="offered(name).length > 1" class="space-y-2">
                            <p class="text-sm font-medium text-fg">{{ t('settings.biometrics.positions') }}</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div v-for="row in positionRows(offered(name), options.modalities[name].groups)" :key="row.group ?? ''" class="space-y-1.5" :data-position-group="row.group">
                                    <p v-if="row.group" class="text-xs font-medium text-muted">{{ label('groups', row.group) }}</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="position in row.positions"
                                            :key="position"
                                            type="button"
                                            :aria-pressed="modality(name).positions.includes(position)"
                                            class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition disabled:opacity-60"
                                            :class="modality(name).positions.includes(position) ? 'border-brand-ink bg-brand/12 text-brand-ink' : 'border-line text-muted hover:text-fg'"
                                            :data-position="position"
                                            @click="togglePosition(name, position)"
                                        >
                                            <span v-if="modality(name).positions.includes(position)" class="flex size-4 items-center justify-center rounded-full bg-brand text-[10px] font-semibold text-brand-contrast">{{ modality(name).positions.indexOf(position) + 1 }}</span>
                                            {{ label('positions', position) }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p class="text-xs" :class="error(`modalities.${name}.positions`) ? 'text-danger' : 'text-muted'">{{ error(`modalities.${name}.positions`) ?? t('settings.biometrics.positionsHint') }}</p>
                        </div>
                        <p v-else-if="error(`modalities.${name}.positions`)" class="text-xs text-danger">{{ error(`modalities.${name}.positions`) }}</p>

                        <div v-if="modality(name).positions.length > 1" class="space-y-1.5">
                            <p class="text-sm font-medium text-fg">{{ t('settings.biometrics.order') }}</p>
                            <ol class="divide-y divide-line rounded-lg border border-line text-sm" :data-position-order="name">
                                <li v-for="(position, index) in modality(name).positions" :key="position" class="flex items-center gap-2 px-3 py-1.5">
                                    <span class="w-5 text-xs text-muted">{{ index + 1 }}</span>
                                    <span class="flex-1 text-fg">{{ label('positions', position) }}</span>
                                    <button type="button" class="rounded p-1 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-30" :disabled="index === 0" :aria-label="t('settings.biometrics.up')" @click="movePosition(name, index, -1)"><ArrowUp class="size-4" /></button>
                                    <button type="button" class="rounded p-1 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-30" :disabled="index === modality(name).positions.length - 1" :aria-label="t('settings.biometrics.down')" @click="movePosition(name, index, 1)"><ArrowDown class="size-4" /></button>
                                </li>
                            </ol>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <UiInput
                                v-if="offered(name).length > 1"
                                v-model="modality(name).count"
                                type="number"
                                :label="t('settings.biometrics.count')"
                                :hint="t('settings.biometrics.countHint', { max: number(maxCount(name)) })"
                                :error="error(`modalities.${name}.count`)"
                                :data-setting="`biometrics.${name}.count`"
                                @change="clampCount(name)"
                            />
                            <UiInput v-model="modality(name).min_quality" type="number" :label="t('settings.biometrics.minQuality')" :hint="t('settings.biometrics.minQualityHint')" :error="error(`modalities.${name}.min_quality`)" :data-setting="`biometrics.${name}.min_quality`" />
                            <UiInput v-model="modality(name).max_attempts" type="number" :label="t('settings.biometrics.maxAttempts')" :hint="t('settings.biometrics.maxAttemptsHint')" :error="error(`modalities.${name}.max_attempts`)" :data-setting="`biometrics.${name}.max_attempts`" />
                        </div>
                        <UiCheckbox
                            v-if="offered(name).length > 1"
                            :model-value="modality(name).substitutes"
                            :label="t('settings.biometrics.substitutes')"
                            :description="t('settings.biometrics.substitutesHint')"
                            :data-setting="`biometrics.${name}.substitutes`"
                            @update:model-value="toggleSubstitutes(name, !!$event)"
                        />

                        <div class="space-y-2">
                            <p class="text-sm font-medium text-fg">{{ t('settings.biometrics.drivers') }}</p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <div v-for="driver in options.modalities[name].drivers" :key="driver" class="flex items-start gap-2">
                                    <UiCheckbox :model-value="modality(name).drivers.includes(driver)" :label="label('drivers', driver)" :description="options.drivers[driver]?.local_url ? t('settings.biometrics.localService', { url: options.drivers[driver].local_url }) : undefined" :data-driver="driver" @update:model-value="toggleDriver(name, driver, !!$event)" />
                                    <UiBadge v-if="options.drivers[driver]?.testing" tone="warning">{{ t('settings.biometrics.testOnly') }}</UiBadge>
                                </div>
                            </div>
                            <p class="text-xs" :class="error(`modalities.${name}.drivers`) ? 'text-danger' : 'text-muted'">{{ error(`modalities.${name}.drivers`) ?? t('settings.biometrics.driversHint') }}</p>
                        </div>
                    </template>
                </section>

                <UiCheckbox v-model="settings.family_members" :label="t('settings.biometrics.familyMembers')" :description="t('settings.biometrics.familyMembersHint')" data-setting="biometrics.family_members" />
                <div class="space-y-2">
                    <p class="text-sm font-medium text-fg">{{ t('settings.biometrics.requiredBefore') }}</p>
                    <div class="flex flex-wrap gap-x-6 gap-y-2">
                        <UiCheckbox
                            v-for="moment in options.required_before"
                            :key="moment"
                            :model-value="settings.required_before.includes(moment)"
                            :label="te(`settings.biometrics.moments.${moment}`) ? t(`settings.biometrics.moments.${moment}`) : moment"
                            :data-moment="moment"
                            @update:model-value="toggleMoment(moment, !!$event)"
                        />
                    </div>
                    <p class="text-xs" :class="error('required_before') ? 'text-danger' : 'text-muted'">{{ error('required_before') ?? t('settings.biometrics.requiredBeforeHint') }}</p>
                </div>
                <UiCheckbox
                    v-if="printableKinds.length > 0"
                    v-model="settings.print_on_card"
                    :label="t('settings.biometrics.printOnCard', { kinds: printableKinds.join(', ') })"
                    :description="t('settings.biometrics.printOnCardHint')"
                    data-setting="biometrics.print_on_card"
                />
                <div v-if="printableKinds.length > 0 && settings.print_on_card" class="space-y-2 ps-7" data-setting="biometrics.print_source">
                    <p class="text-sm font-medium text-fg">{{ t('settings.biometrics.printSource') }}</p>
                    <div role="radiogroup" :aria-label="t('settings.biometrics.printSource')" class="inline-flex max-w-full overflow-x-auto rounded-lg border border-line p-0.5">
                        <button
                            v-for="source in options.print_sources"
                            :key="source"
                            type="button"
                            role="radio"
                            :aria-checked="settings.print_source === source"
                            class="rounded-md px-3 py-1 text-sm whitespace-nowrap transition"
                            :class="settings.print_source === source ? 'bg-brand text-brand-contrast' : 'text-muted hover:text-fg'"
                            :data-print-source="source"
                            @click="settings.print_source = source"
                        >
                            {{ te(`settings.biometrics.printSources.${source}`) ? t(`settings.biometrics.printSources.${source}`) : source }}
                        </button>
                    </div>
                    <p class="text-xs" :class="error('print_source') ? 'text-danger' : 'text-muted'">
                        {{ error('print_source') ?? (te(`settings.biometrics.printSourceHints.${settings.print_source}`) ? t(`settings.biometrics.printSourceHints.${settings.print_source}`) : '') }}
                    </p>
                </div>
            </template>
        </fieldset>
    </UiCard>
</template>
