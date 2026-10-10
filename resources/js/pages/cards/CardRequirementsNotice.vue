<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ShieldAlert } from 'lucide-vue-next';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import type { CardRequirementProblem } from '@/types';

/**
 * What a person is missing before a card can be issued or printed (the organization's card requirements),
 * with a link to where it is completed and, for users who may, going ahead anyway with a reason that is
 * kept in the card's audit log. Used by the issue dialog and the print page.
 */
defineProps<{ problems: CardRequirementProblem[]; moment: 'issue' | 'print'; canOverride: boolean }>();
const overriding = defineModel<boolean>('overriding', { default: false });
const reason = defineModel<string>('reason', { default: '' });
const { t, te } = useI18n();

/** Where a problem is fixed: the person's tab named by the requirement (e.g. biometrics), else their page. */
function fixLink(problem: CardRequirementProblem): string | null {
    const holder = problem.details.cardholder;
    if (!holder) {
        return null;
    }
    const tab = te(`cards.requirementsUi.fix.${problem.code}`) ? `?tab=${problem.code}` : '';

    return `/cardholders/${holder}${tab}`;
}
</script>

<template>
    <div class="space-y-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm text-fg" role="alert" data-card-requirements>
        <p class="flex items-center gap-2 font-medium"><ShieldAlert class="size-4 shrink-0 text-warning" />{{ t(`cards.requirementsUi.title.${moment}`) }}</p>
        <ul class="space-y-1.5 text-xs">
            <li v-for="(problem, index) in problems" :key="`${problem.code}-${problem.card ?? ''}-${index}`" class="flex flex-wrap items-baseline gap-x-2" :data-requirement="problem.code">
                <span v-if="problem.card" class="font-mono text-muted">{{ problem.card }}</span>
                <span>{{ problem.message }}</span>
                <RouterLink v-if="fixLink(problem)" :to="fixLink(problem)!" target="_blank" class="text-brand-ink hover:underline" data-requirement-fix>
                    {{ te(`cards.requirementsUi.fix.${problem.code}`) ? t(`cards.requirementsUi.fix.${problem.code}`) : t('cards.requirementsUi.fix.default') }}
                </RouterLink>
            </li>
        </ul>
        <template v-if="canOverride">
            <UiCheckbox v-model="overriding" :label="t(`cards.requirementsUi.override.${moment}`)" :description="t('cards.requirementsUi.overrideHint')" data-requirements-override />
            <UiTextarea v-if="overriding" v-model="reason" :label="t('cards.requirementsUi.reason')" required :maxlength="500" :rows="2" data-requirements-reason />
        </template>
        <p v-else class="text-xs text-muted">{{ t('cards.requirementsUi.noOverride') }}</p>
    </div>
</template>
