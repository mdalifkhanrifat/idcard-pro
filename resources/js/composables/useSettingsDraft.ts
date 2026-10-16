import { computed, onBeforeUnmount, ref, type Ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useConfirm } from '@/composables/useConfirm';
import { toApiError } from '@/lib/http';
import { changedParts } from '@/lib/settings';
import { useUiStore } from '@/stores/ui';

/**
 * A settings page's draft: the settings as saved (with what the server sends beside them, e.g. options) and a copy
 * being changed. A save sends only the parts (top-level keys) that changed — the settings APIs take a partial
 * change, so what another tab or person changed meanwhile is not overwritten. Unsaved changes are not lost by
 * leaving: asked first, in the app and when closing or reloading the tab. The server's answer to a save fills
 * the form again; a refused save keeps its field errors by field and always says so in a message too (a field
 * the page does not show would otherwise fail silently).
 */
export function useSettingsDraft<TResponse, TSettings>(config: {
    fetch: () => Promise<TResponse>;
    /** Saves the changed parts. */
    store: (changes: Partial<TSettings>) => Promise<TResponse>;
    /** The settings inside the server's answer. */
    settingsOf: (response: TResponse) => TSettings;
    /** What is lost by leaving, e.g. "Your changes to the notification settings will be lost." */
    leaveHint: () => string;
}) {
    const { t } = useI18n();
    const ui = useUiStore();
    const { confirm } = useConfirm();

    const data = ref<TResponse | null>(null) as Ref<TResponse | null>;
    const draft = ref<TSettings | null>(null) as Ref<TSettings | null>;
    const loading = ref(false);
    const failed = ref(false);
    const saving = ref(false);
    const errors = ref<Record<string, string[]>>({});

    function show(response: TResponse): void {
        data.value = response;
        // A plain copy (a reactive object cannot be structured-cloned)
        draft.value = JSON.parse(JSON.stringify(config.settingsOf(response))) as TSettings;
        errors.value = {};
    }

    async function load(): Promise<void> {
        loading.value = true;
        failed.value = false;
        try {
            show(await config.fetch());
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
    }

    const dirty = computed(() => !!data.value && !!draft.value && JSON.stringify(draft.value) !== JSON.stringify(config.settingsOf(data.value)));

    async function save(): Promise<void> {
        if (!draft.value) {
            return;
        }
        saving.value = true;
        errors.value = {};
        try {
            show(await config.store(data.value ? changedParts(draft.value as object, config.settingsOf(data.value) as object) as Partial<TSettings> : draft.value));
            ui.notify(t('common.saved'));
        } catch (error) {
            const apiError = toApiError(error);
            errors.value = apiError.errors;
            ui.notify(apiError.message, 'error');
        } finally {
            saving.value = false;
        }
    }

    function discard(): void {
        if (data.value) {
            show(data.value);
        }
    }

    onBeforeRouteLeave(async () => !dirty.value || (await confirm({ title: t('common.unsaved.leaveConfirm'), message: config.leaveHint(), confirmLabel: t('common.unsaved.leave'), danger: true })));
    function warnOnUnload(event: BeforeUnloadEvent): void {
        if (dirty.value) {
            event.preventDefault();
        }
    }
    window.addEventListener('beforeunload', warnOnUnload);
    onBeforeUnmount(() => window.removeEventListener('beforeunload', warnOnUnload));

    return { data, draft, loading, failed, saving, errors, dirty, load, save, discard };
}
