<script setup lang="ts">
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { Database, ImageIcon, PenLine, QrCode, Signature, Type, UserRound, Wallpaper } from 'lucide-vue-next';
import type { DesignElementType } from '@/types';

/**
 * "Add element" buttons (legacy "Add Section" + section type select).
 */
defineProps<{ hasBackground: boolean }>();
const emit = defineEmits<{ add: [type: DesignElementType] }>();
const { t } = useI18n();

const TYPES: { type: DesignElementType; icon: Component }[] = [
    { type: 'field', icon: Database },
    { type: 'text', icon: Type },
    { type: 'photo', icon: UserRound },
    { type: 'logo', icon: ImageIcon },
    { type: 'signatory_signature', icon: Signature },
    { type: 'cardholder_signature', icon: PenLine },
    { type: 'qr_code', icon: QrCode },
    { type: 'background', icon: Wallpaper },
];
</script>

<template>
    <div class="grid grid-cols-2 gap-1.5">
        <button
            v-for="item in TYPES"
            :key="item.type"
            class="flex flex-col items-center gap-1 rounded-lg border border-line px-1 py-2 text-center text-xs text-fg hover:border-brand hover:bg-brand/5 hover:text-brand"
            :data-add="item.type"
            :title="item.type === 'background' && hasBackground ? t('designer.backgroundExists') : undefined"
            @click="emit('add', item.type)"
        >
            <component :is="item.icon" class="size-4" />
            <span class="leading-tight">{{ t(`designer.types.${item.type}`) }}</span>
        </button>
    </div>
</template>
