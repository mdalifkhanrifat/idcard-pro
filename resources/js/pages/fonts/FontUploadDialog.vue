<script setup lang="ts">
import { reactive, ref, useTemplateRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { FileType2 } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { http, toApiError } from '@/lib/http';
import { formatBytes } from '@/lib/format';
import { useUiStore } from '@/stores/ui';

/**
 * Upload one font file (a weight and style of a family). An empty name uses the name stored in the font.
 */
const props = defineProps<{ family?: string }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ saved: [] }>();
const { t } = useI18n();
const ui = useUiStore();

const WEIGHTS = [100, 200, 300, 400, 500, 600, 700, 800, 900];
const MAX_BYTES = 15 * 1024 * 1024;

const fileInput = useTemplateRef<HTMLInputElement>('file');
const file = ref<File | null>(null);
const form = reactive({ family: '', weight: 400 as number | string, style: 'normal', licence_confirmed: false });
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

watch(open, (value) => {
    if (value) {
        Object.assign(form, { family: props.family ?? '', weight: 400, style: 'normal', licence_confirmed: false });
        file.value = null;
        errors.value = {};
    }
});

function pick(event: Event): void {
    const chosen = (event.target as HTMLInputElement).files?.[0] ?? null;
    errors.value = {};
    if (chosen && chosen.size > MAX_BYTES) {
        errors.value = { file: [t('fonts.tooLarge', { size: formatBytes(MAX_BYTES) })] };
        file.value = null;

        return;
    }
    file.value = chosen;
    // Bold/italic file names usually say so; a guess the user can change
    const name = chosen?.name.toLowerCase() ?? '';
    form.weight = /black|heavy/.test(name) ? 900 : /semi-?bold/.test(name) ? 600 : /bold/.test(name) ? 700 : /light/.test(name) ? 300 : /medium/.test(name) ? 500 : 400;
    form.style = /italic|oblique/.test(name) ? 'italic' : 'normal';
}

async function submit(): Promise<void> {
    if (!file.value) {
        errors.value = { file: [t('fonts.chooseFile')] };

        return;
    }
    busy.value = true;
    errors.value = {};
    const body = new FormData();
    body.append('file', file.value);
    if (form.family.trim()) {
        body.append('family', form.family.trim());
    }
    body.append('weight', String(form.weight));
    body.append('style', form.style);
    body.append('licence_confirmed', form.licence_confirmed ? '1' : '0');
    try {
        await http.post('/fonts', body);
        ui.notify(t('fonts.uploaded'));
        open.value = false;
        emit('saved');
    } catch (error) {
        const apiError = toApiError(error);
        errors.value = apiError.errors;
        if (!Object.keys(apiError.errors).length) {
            ui.notify(apiError.message, 'error');
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <UiDialog v-model:open="open" :title="t('fonts.upload')" :description="t('fonts.uploadHint')">
        <form id="font-form" class="space-y-4" @submit.prevent="submit">
            <div class="space-y-1.5">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg border border-dashed border-line px-4 py-3 text-left hover:border-brand hover:bg-surface-2"
                    data-pick-font
                    @click="fileInput?.click()"
                >
                    <FileType2 class="size-6 shrink-0 text-muted" />
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-fg">{{ file ? file.name : t('fonts.chooseFile') }}</span>
                        <span class="block text-xs text-muted">{{ file ? formatBytes(file.size) : t('fonts.formats') }}</span>
                    </span>
                </button>
                <input ref="file" type="file" accept=".ttf,.otf,.woff,.woff2,font/ttf,font/otf,font/woff,font/woff2" class="hidden" data-font-file @change="pick" />
                <p v-if="errors.file" class="text-xs text-danger">{{ errors.file[0] }}</p>
            </div>
            <UiInput v-model="form.family" :label="t('fonts.family')" :placeholder="t('fonts.familyPlaceholder')" :hint="t('fonts.familyHint')" :maxlength="60" :error="errors.family" />
            <div class="grid grid-cols-2 gap-3">
                <UiSelect v-model="form.weight" :label="t('fonts.weight')" :options="WEIGHTS.map((weight) => ({ value: weight, label: t(`fonts.weights.${weight}`) }))" :error="errors.weight" />
                <UiSelect v-model="form.style" :label="t('fonts.style')" :options="[{ value: 'normal', label: t('fonts.styles.normal') }, { value: 'italic', label: t('fonts.styles.italic') }]" :error="errors.style" />
            </div>
            <div class="space-y-1">
                <UiCheckbox v-model="form.licence_confirmed" :label="t('fonts.licence')" :description="t('fonts.licenceHint')" data-licence />
                <p v-if="errors.licence_confirmed" class="text-xs text-danger">{{ errors.licence_confirmed[0] }}</p>
            </div>
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="font-form" :loading="busy" data-upload-font>{{ t('fonts.upload') }}</UiButton>
        </template>
    </UiDialog>
</template>
