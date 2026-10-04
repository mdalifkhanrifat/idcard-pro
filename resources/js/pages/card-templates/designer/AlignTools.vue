<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import {
    AlignCenterHorizontal,
    AlignCenterVertical,
    AlignEndHorizontal,
    AlignEndVertical,
    AlignHorizontalDistributeCenter,
    AlignStartHorizontal,
    AlignStartVertical,
    AlignVerticalDistributeCenter,
} from 'lucide-vue-next';
import type { AlignMode } from './useDesigner';

/**
 * Align buttons: with several elements selected they line up with each other, with one it lines up on the card.
 */
const props = defineProps<{ count: number }>();
const emit = defineEmits<{ align: [mode: AlignMode]; distribute: [axis: 'horizontal' | 'vertical'] }>();
const { t } = useI18n();

const MODES = [
    { mode: 'left', icon: AlignStartVertical },
    { mode: 'center', icon: AlignCenterVertical },
    { mode: 'right', icon: AlignEndVertical },
    { mode: 'top', icon: AlignStartHorizontal },
    { mode: 'middle', icon: AlignCenterHorizontal },
    { mode: 'bottom', icon: AlignEndHorizontal },
] as const;
</script>

<template>
    <div class="space-y-1">
        <span class="text-xs font-medium text-muted">{{ t(props.count > 1 ? 'designer.alignSelection' : 'designer.alignCard') }}</span>
        <div class="flex flex-wrap gap-0.5">
            <button
                v-for="item in MODES"
                :key="item.mode"
                class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-fg"
                :title="t(`designer.alignModes.${item.mode}`)"
                :data-align-to="item.mode"
                @click="emit('align', item.mode)"
            >
                <component :is="item.icon" class="size-4" />
            </button>
            <template v-if="count > 2">
                <span class="mx-1 h-5 w-px self-center bg-line" />
                <button class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-fg" :title="t('designer.distributeHorizontal')" data-distribute="horizontal" @click="emit('distribute', 'horizontal')">
                    <AlignHorizontalDistributeCenter class="size-4" />
                </button>
                <button class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-fg" :title="t('designer.distributeVertical')" data-distribute="vertical" @click="emit('distribute', 'vertical')">
                    <AlignVerticalDistributeCenter class="size-4" />
                </button>
            </template>
        </div>
    </div>
</template>
