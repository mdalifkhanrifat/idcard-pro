<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

/**
 * Choose some of a list and put them in order (e.g. channels, languages): the chosen first, in their order, with
 * buttons to move them; the rest below, unticked. With `min`, the last ones cannot be unticked.
 */
const props = withDefaults(defineProps<{ options: { value: string; label: string }[]; disabled?: boolean; min?: number; name: string }>(), { min: 0 });
const model = defineModel<string[]>({ required: true });
const { t } = useI18n();

const labelOf = (value: string) => props.options.find((option) => option.value === value)?.label ?? value;
const rest = computed(() => props.options.filter((option) => !model.value.includes(option.value)));
const locked = computed(() => model.value.length <= props.min);

function toggle(value: string, on: boolean): void {
    model.value = on ? [...model.value, value] : model.value.filter((chosen) => chosen !== value);
}

function move(index: number, step: -1 | 1): void {
    const chosen = [...model.value];
    [chosen[index], chosen[index + step]] = [chosen[index + step], chosen[index]];
    model.value = chosen;
}
</script>

<template>
    <ul class="divide-y divide-line rounded-lg border border-line text-sm" :data-choice="name">
        <li v-for="(value, index) in model" :key="value" class="flex items-center gap-2 px-3 py-2" :data-chosen="value">
            <input
                type="checkbox"
                checked
                class="size-4 rounded border-line accent-[var(--brand)]"
                :disabled="disabled || locked"
                :aria-label="labelOf(value)"
                @change="toggle(value, false)"
            />
            <span class="w-5 text-xs text-muted">{{ index + 1 }}</span>
            <span class="min-w-0 flex-1 break-words text-fg">{{ labelOf(value) }}</span>
            <template v-if="model.length > 1">
                <button type="button" class="rounded p-1 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-30" :disabled="disabled || index === 0" :aria-label="t('settings.verification.up')" @click="move(index, -1)"><ArrowUp class="size-4" /></button>
                <button type="button" class="rounded p-1 text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-30" :disabled="disabled || index === model.length - 1" :aria-label="t('settings.verification.down')" @click="move(index, 1)"><ArrowDown class="size-4" /></button>
            </template>
        </li>
        <li v-for="option in rest" :key="option.value" class="flex items-center gap-2 px-3 py-2" :data-unchosen="option.value">
            <input type="checkbox" class="size-4 rounded border-line accent-[var(--brand)]" :disabled="disabled" :aria-label="option.label" @change="toggle(option.value, true)" />
            <span class="w-5" />
            <span class="min-w-0 flex-1 break-words text-muted">{{ option.label }}</span>
        </li>
    </ul>
</template>
