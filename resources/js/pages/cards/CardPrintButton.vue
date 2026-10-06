<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Printer } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import { openPrintPage } from './cards';
import { useAuthStore } from '@/stores/auth';
import type { IssuedCard } from '@/types';

/**
 * Opens the print page for a card in a new tab. Shown only to people allowed to print, and only for
 * cards that may be printed (the server's `printable`: active and not expired).
 */
const props = withDefaults(defineProps<{ card: Pick<IssuedCard, 'id' | 'number' | 'printable'>; compact?: boolean }>(), { compact: false });
const { t } = useI18n();
const auth = useAuthStore();
</script>

<template>
    <UiButton
        v-if="auth.can('cards.print') && props.card.printable"
        :variant="compact ? 'ghost' : 'secondary'"
        size="sm"
        :title="t('cards.print.open', { number: card.number })"
        :aria-label="t('cards.print.open', { number: card.number })"
        data-print-card
        @click.stop="openPrintPage([card.id])"
    >
        <Printer class="size-4" /><span v-if="!compact">{{ t('cards.print.print') }}</span>
    </UiButton>
</template>
