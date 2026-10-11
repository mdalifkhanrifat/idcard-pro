<script setup lang="ts">
import { computed, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { Barcode, Database, Fingerprint, ImageIcon, Minus, PanelTop, PenLine, QrCode, Signature, Square, Type, UserRound, Wallpaper } from 'lucide-vue-next';
import type { DesignElementType } from '@/types';

/**
 * "Add element" buttons (legacy "Add Section" + section type select).
 */
const props = defineProps<{ hasBackground: boolean; /** The organization prints biometric images on cards (catalog `biometric_fields`). */ biometric?: boolean }>();
const emit = defineEmits<{ add: [type: DesignElementType, preset?: string] }>();
const { t } = useI18n();

type PaletteItem = { type: DesignElementType; preset?: string; icon: Component };

const TYPES: PaletteItem[] = [
    { type: 'field', icon: Database },
    { type: 'text', icon: Type },
    { type: 'photo', icon: UserRound },
    { type: 'logo', icon: ImageIcon },
    { type: 'signatory_signature', icon: Signature },
    { type: 'cardholder_signature', icon: PenLine },
    { type: 'qr_code', icon: QrCode },
    { type: 'barcode', icon: Barcode },
    { type: 'shape', icon: Square },
    { type: 'shape', preset: 'line', icon: Minus },
    { type: 'shape', preset: 'band', icon: PanelTop },
    { type: 'background', icon: Wallpaper },
];
const items = computed<PaletteItem[]>(() => (props.biometric ? [...TYPES, { type: 'biometric', icon: Fingerprint }] : TYPES));
</script>

<template>
    <div class="grid grid-cols-2 gap-1.5">
        <button
            v-for="item in items"
            :key="item.preset ?? item.type"
            class="flex flex-col items-center gap-1 rounded-lg border border-line px-1 py-2 text-center text-xs text-fg hover:border-brand-ink hover:bg-surface-2"
            :data-add="item.preset ?? item.type"
            :title="item.type === 'background' && hasBackground ? t('designer.backgroundExists') : undefined"
            @click="emit('add', item.type, item.preset)"
        >
            <component :is="item.icon" class="size-4" />
            <span class="leading-tight">{{ t(item.preset ? `designer.presets.${item.preset}` : `designer.types.${item.type}`) }}</span>
        </button>
    </div>
</template>
