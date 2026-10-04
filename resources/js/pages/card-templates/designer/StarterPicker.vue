<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import UiDialog from '@/components/ui/UiDialog.vue';
import DesignerCanvas from './DesignerCanvas.vue';
import { sampleValue } from './sample';
import { STARTERS } from './starters';
import type { LayoutFile } from './transfer';
import type { DesignerItem } from './useDesigner';

/**
 * Ready-made designs, previewed with sample data.
 */
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ apply: [file: LayoutFile] }>();
const { t } = useI18n();

function front(file: LayoutFile): DesignerItem[] {
    return file.elements.filter((element) => element.side === 'front').map((element, index) => ({ ...element, image: null, image_url: null, key: index + 1 }));
}

function valueOf(item: DesignerItem): string {
    return sampleValue(item.field, { 'organization.name': 'Example Ltd' });
}
</script>

<template>
    <UiDialog v-model:open="open" :title="t('designer.startersTitle')" :description="t('designer.startersHint')" size="lg">
        <div class="grid gap-4 sm:grid-cols-3">
            <button
                v-for="starter in STARTERS"
                :key="starter.key"
                class="flex flex-col items-center gap-2 rounded-xl border border-line p-3 hover:border-brand hover:bg-surface-2"
                :data-starter="starter.key"
                @click="emit('apply', starter.file)"
            >
                <div class="pointer-events-none flex h-36 items-center">
                    <DesignerCanvas
                        :width="starter.file.size_mm.width"
                        :height="starter.file.size_mm.height"
                        :scale="130 / Math.max(starter.file.size_mm.width, starter.file.size_mm.height)"
                        :items="front(starter.file)"
                        :selected-key="null"
                        :value-of="valueOf"
                        :editable="false"
                        bare
                    />
                </div>
                <span class="text-sm font-medium text-fg">{{ t(`designer.starters.${starter.key}`) }}</span>
            </button>
        </div>
    </UiDialog>
</template>
