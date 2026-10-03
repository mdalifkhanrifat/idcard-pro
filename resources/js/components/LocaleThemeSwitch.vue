<script setup lang="ts">
import { Languages, Moon, Sun } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { LOCALES, setLocale } from '@/lib/i18n';
import { useUiStore } from '@/stores/ui';

const ui = useUiStore();
const { locale, t } = useI18n();

function cycleLocale(): void {
    const index = LOCALES.findIndex((l) => l.code === locale.value);
    setLocale(LOCALES[(index + 1) % LOCALES.length].code);
}
</script>

<template>
    <div class="flex items-center gap-1">
        <button
            class="inline-flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-sm text-muted hover:bg-surface-2 hover:text-fg"
            :aria-label="t('common.language')"
            @click="cycleLocale"
        >
            <Languages class="size-4" />
            <span>{{ LOCALES.find((l) => l.code === locale)?.label }}</span>
        </button>
        <button
            class="inline-flex size-9 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg"
            :aria-label="t('common.theme')"
            @click="ui.toggleTheme()"
        >
            <component :is="ui.theme === 'dark' ? Sun : Moon" class="size-4" />
        </button>
    </div>
</template>
