<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Layers, ZoomIn, ZoomOut } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import DesignerCanvas from './designer/DesignerCanvas.vue';
import { sampleValue } from './designer/sample';
import { useDesigner, type DesignerItem } from './designer/useDesigner';
import { useAuthStore } from '@/stores/auth';
import type { CardSide } from '@/types';

const { t, te } = useI18n();
const route = useRoute();
const auth = useAuthStore();
const designer = useDesigner();
const { template, catalog, side, sideItems, selected, selectedKey, loading, size } = designer;

/** Screen pixels per millimetre. */
const ZOOM_STEPS = [3, 4, 5, 6, 7, 8, 10, 12];
/** 100% = the card's real size on a 96 dpi screen. */
const ACTUAL_SIZE = 96 / 25.4;
const zoom = ref(3);
const scale = computed(() => ZOOM_STEPS[zoom.value]);
const stage = useTemplateRef<HTMLElement>('stage');

/** Largest zoom at which the whole card fits the canvas area. */
function fitZoom(): void {
    const room = (stage.value?.clientWidth ?? 600) - 48;
    let fitting = 0;
    ZOOM_STEPS.forEach((step, index) => {
        if (step * size.value.width <= room) {
            fitting = index;
        }
    });
    zoom.value = fitting;
}

const sides = computed<CardSide[]>(() => (template.value?.is_double_sided ? ['front', 'back'] : ['front']));

onMounted(async () => {
    await designer.load(String(route.params.id));
    requestAnimationFrame(fitZoom);
});

function fieldLabel(field: string | null): string {
    if (!field) {
        return '';
    }
    const custom = catalog.value?.custom_fields.find((item) => item.key === field);
    if (custom) {
        return custom.label;
    }

    return te(`designer.fields.${field}`) ? t(`designer.fields.${field}`) : field;
}

function valueOf(item: DesignerItem): string {
    return sampleValue(item.field, { 'organization.name': auth.tenant?.name ?? '' });
}

/** Short name for the layer list. */
function itemTitle(item: DesignerItem): string {
    if (item.type === 'field') {
        return fieldLabel(item.field) || t('designer.types.field');
    }
    if (item.type === 'text') {
        return item.content || t('designer.types.text');
    }

    return t(`designer.types.${item.type}`);
}
</script>

<template>
    <div v-if="template" class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <RouterLink :to="{ name: 'card-templates' }" class="rounded-md p-2 text-muted hover:bg-surface-2 hover:text-fg" :title="t('common.back')">
                <ArrowLeft class="size-5" />
            </RouterLink>
            <div class="min-w-0">
                <h1 class="truncate text-lg font-semibold text-fg">{{ template.name }}</h1>
                <p class="text-xs text-muted">{{ t('designer.subtitle', { width: size.width, height: size.height }) }}</p>
            </div>
            <UiBadge>{{ t('templates.version', { n: template.version }) }}</UiBadge>

            <div class="ml-auto flex items-center gap-2">
                <div v-if="sides.length > 1" class="inline-flex rounded-lg border border-line bg-surface p-0.5" role="tablist">
                    <button
                        v-for="value in sides"
                        :key="value"
                        role="tab"
                        :aria-selected="side === value"
                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="side === value ? 'bg-brand text-brand-contrast' : 'text-muted hover:text-fg'"
                        @click="designer.showSide(value)"
                    >
                        {{ t(`designer.sides.${value}`) }}
                    </button>
                </div>
                <div class="inline-flex items-center rounded-lg border border-line bg-surface">
                    <button class="p-2 text-muted hover:text-fg disabled:opacity-40" :disabled="zoom === 0" :title="t('designer.zoomOut')" @click="zoom--"><ZoomOut class="size-4" /></button>
                    <span class="w-12 text-center text-xs text-muted tabular-nums">{{ Math.round((scale / ACTUAL_SIZE) * 100) }}%</span>
                    <button class="p-2 text-muted hover:text-fg disabled:opacity-40" :disabled="zoom === ZOOM_STEPS.length - 1" :title="t('designer.zoomIn')" @click="zoom++"><ZoomIn class="size-4" /></button>
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)_18rem]">
            <aside class="space-y-2 rounded-xl border border-line bg-surface p-3">
                <h2 class="flex items-center gap-2 text-sm font-medium text-fg"><Layers class="size-4" />{{ t('designer.layers') }}</h2>
                <p v-if="!sideItems.length" class="text-xs text-muted">{{ t('designer.noElements') }}</p>
                <!-- Top layer first, like most design tools -->
                <button
                    v-for="item in [...sideItems].reverse()"
                    :key="item.key"
                    class="block w-full truncate rounded-md px-2 py-1.5 text-left text-sm"
                    :class="item.key === selectedKey ? 'bg-brand/10 text-brand' : 'text-fg hover:bg-surface-2'"
                    @click="selectedKey = item.key"
                >
                    {{ itemTitle(item) }}
                </button>
            </aside>

            <section ref="stage" class="order-first flex min-h-[24rem] overflow-auto lg:order-none rounded-xl border border-line bg-surface-2 p-6">
                <DesignerCanvas
                    class="m-auto"
                    :width="size.width"
                    :height="size.height"
                    :scale="scale"
                    :items="sideItems"
                    :selected-key="selectedKey"
                    :value-of="valueOf"
                    @select="selectedKey = $event"
                />
            </section>

            <aside class="rounded-xl border border-line bg-surface p-4 text-sm">
                <template v-if="selected">
                    <p class="font-medium text-fg">{{ itemTitle(selected) }}</p>
                    <p class="mt-1 text-xs text-muted">{{ t('designer.position', { x: selected.x, y: selected.y, width: selected.width, height: selected.height }) }}</p>
                </template>
                <p v-else class="text-muted">{{ t('designer.selectHint') }}</p>
            </aside>
        </div>
    </div>
    <p v-else-if="loading" class="py-16 text-center text-sm text-muted">{{ t('common.loading') }}</p>
</template>
