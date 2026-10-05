<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Trash2, TriangleAlert } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import FontUploadDialog from './FontUploadDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { http, toApiError } from '@/lib/http';
import { formatBytes } from '@/lib/format';
import { loadTenantFonts } from '@/lib/tenantFonts';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { TenantFont } from '@/types';

/**
 * The organization's licensed fonts (e.g. SolaimanLipi, Nikosh) for card templates, grouped by family,
 * each file previewed in its own face.
 */
const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const fonts = ref<TenantFont[]>([]);
const failed = ref<string[]>([]);
const loading = ref(true);
const sample = ref('আমার সোনার বাংলা · The quick brown fox · 0123456789');
const dialogOpen = ref(false);
const uploadFamily = ref<string | undefined>();

const families = computed(() => {
    const groups = new Map<string, TenantFont[]>();
    for (const font of fonts.value) {
        groups.set(font.family, [...(groups.get(font.family) ?? []), font]);
    }

    return [...groups.entries()].map(([family, faces]) => ({ family, faces }));
});

async function load(): Promise<void> {
    loading.value = true;
    try {
        fonts.value = (await http.get('/fonts')).data.data;
        failed.value = (await loadTenantFonts(fonts.value)).map((font) => `${font.family}|${font.weight}|${font.style}`);
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function upload(family?: string): void {
    uploadFamily.value = family;
    dialogOpen.value = true;
}

async function remove(font: TenantFont): Promise<void> {
    const label = `${font.family} · ${t(`fonts.weights.${font.weight}`)}${font.style === 'italic' ? ` · ${t('fonts.styles.italic')}` : ''}`;
    if (!(await confirm({ title: t('fonts.deleteConfirm', { name: label }), message: t('fonts.deleteHint'), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/fonts/${font.id}`);
        ui.notify(t('common.deleted'));
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('fonts.title')" :subtitle="t('fonts.subtitle')">
            <UiButton v-if="auth.can('fonts.create')" data-new-font @click="upload()"><Plus class="size-4" />{{ t('fonts.upload') }}</UiButton>
        </UiPageHeader>

        <div class="space-y-4">
            <label class="block rounded-xl border border-line bg-surface p-4">
                <span class="mb-1.5 block text-xs font-medium text-muted">{{ t('fonts.sampleText') }}</span>
                <input
                    v-model="sample"
                    type="text"
                    maxlength="120"
                    class="h-10 w-full rounded-lg border border-line bg-surface px-3 text-sm focus:border-brand focus:ring-4 focus:ring-[var(--ring)] focus:outline-none"
                    data-sample-text
                />
            </label>

            <section v-for="group in families" :key="group.family" class="rounded-xl border border-line bg-surface" :data-family="group.family">
                <header class="flex items-center justify-between gap-3 border-b border-line px-5 py-3">
                    <h2 class="font-semibold text-fg">{{ group.family }}</h2>
                    <button v-if="auth.can('fonts.create')" class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-muted hover:bg-surface-2 hover:text-fg" @click="upload(group.family)">
                        <Plus class="size-3.5" />{{ t('fonts.addStyle') }}
                    </button>
                </header>
                <ul>
                    <li v-for="font in group.faces" :key="font.id" class="flex items-center gap-4 border-b border-line px-5 py-3 last:border-0" data-font>
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate text-2xl leading-snug text-fg"
                                :style="{ fontFamily: `'${font.family}', sans-serif`, fontWeight: font.weight, fontStyle: font.style }"
                                data-font-sample
                            >
                                {{ sample || font.family }}
                            </p>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-muted">
                                <span class="font-medium text-fg">{{ t(`fonts.weights.${font.weight}`) }}<template v-if="font.style === 'italic'"> · {{ t('fonts.styles.italic') }}</template></span>
                                <span>{{ font.original_name }}</span>
                                <span>{{ font.format.toUpperCase() }} · {{ formatBytes(font.size) }}</span>
                                <span v-if="failed.includes(`${font.family}|${font.weight}|${font.style}`)" class="inline-flex items-center gap-1 text-warning">
                                    <TriangleAlert class="size-3.5" />{{ t('fonts.loadFailed') }}
                                </span>
                            </p>
                        </div>
                        <button v-if="auth.can('fonts.delete')" class="shrink-0 rounded-md p-2 text-muted hover:bg-danger/10 hover:text-danger" :aria-label="t('common.delete')" data-delete-font @click="remove(font)">
                            <Trash2 class="size-4" />
                        </button>
                    </li>
                </ul>
            </section>

            <div v-if="!families.length && !loading" class="rounded-xl border border-line bg-surface">
                <UiEmpty :title="t('fonts.empty')" :hint="t('fonts.emptyHint')" />
            </div>
            <p class="text-xs text-muted">{{ t('fonts.builtIn') }}</p>
        </div>

        <FontUploadDialog v-model:open="dialogOpen" :family="uploadFamily" @saved="load" />
    </div>
</template>
