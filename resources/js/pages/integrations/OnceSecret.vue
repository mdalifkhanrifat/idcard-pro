<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Check, Copy, TriangleAlert } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import { useUiStore } from '@/stores/ui';

/**
 * Something shown this once and never again (an API token, a signing secret). Held by the dialog only: it is never put
 * in the address or in the browser's storage.
 */
const props = defineProps<{ value: string; warning: string }>();
const { t } = useI18n();
const ui = useUiStore();
const copied = ref(false);

watch(() => props.value, () => (copied.value = false));

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.value);
        copied.value = true;
    } catch {
        ui.notify(t('integrations.tokens.copyFailed'), 'error');
    }
}
</script>

<template>
    <section class="rounded-lg border border-warning/40 bg-warning/10 p-4" data-secret-once>
        <p class="flex items-start gap-2 text-sm font-medium text-fg"><TriangleAlert class="mt-0.5 size-4 shrink-0" />{{ warning }}</p>
        <code class="mt-3 block break-all rounded-md bg-surface p-3 font-mono text-xs text-fg" dir="ltr" data-secret-plain>{{ value }}</code>
        <UiButton variant="secondary" class="mt-3" data-secret-copy @click="copy">
            <Check v-if="copied" class="size-4" /><Copy v-else class="size-4" />{{ copied ? t('integrations.tokens.copied') : t('integrations.tokens.copy') }}
        </UiButton>
    </section>
</template>
