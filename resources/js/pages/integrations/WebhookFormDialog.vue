<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import { toApiError } from '@/lib/http';
import { createWebhook, groupState, knownEvents, updateWebhook, withGroup, type Webhook, type WebhookEventGroup, type WebhookWithSecret } from '@/lib/integrations/webhooks';
import { useUiStore } from '@/stores/ui';

const props = defineProps<{ webhook: Webhook | null; groups: WebhookEventGroup[] }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ created: [webhook: WebhookWithSecret]; saved: [] }>();
const { t } = useI18n();
const ui = useUiStore();

const blank = () => ({ name: '', url: '', enabled: true, events: [] as string[] });
const form = reactive(blank());
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);
const isEdit = computed(() => props.webhook !== null);

watch(open, (value) => {
    if (!value) {
        return;
    }
    errors.value = {};
    Object.assign(form, blank(), props.webhook
        ? { name: props.webhook.name, url: props.webhook.url, enabled: props.webhook.enabled, events: knownEvents(props.webhook.events, props.groups) }
        : {});
});

function chooseGroup(group: WebhookEventGroup, on: boolean): void {
    form.events = withGroup(form.events, group, on);
}

async function submit(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        if (props.webhook === null) {
            emit('created', await createWebhook({ ...form }));
        } else {
            await updateWebhook(props.webhook.id, { ...form });
            ui.notify(t('common.saved'));
            emit('saved');
        }
        open.value = false;
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
    <UiDialog v-model:open="open" :title="isEdit ? t('webhooks.editTitle') : t('webhooks.new')" size="lg" full-on-phone>
        <form id="webhook-form" class="space-y-4" data-webhook-form @submit.prevent="submit">
            <UiInput v-model="form.name" :label="t('webhooks.name')" :hint="t('webhooks.nameHint')" required :error="errors.name" />
            <UiInput v-model="form.url" type="url" dir="ltr" :label="t('webhooks.url')" :hint="t('webhooks.urlHint')" required :error="errors.url" data-webhook-url />
            <UiCheckbox v-model="form.enabled" :label="t('webhooks.enabled')" :description="t('webhooks.enabledHint')" data-webhook-enabled />

            <fieldset>
                <legend class="text-sm font-medium text-fg">{{ t('webhooks.events') }}</legend>
                <p v-if="errors.events" class="mt-1 text-xs text-danger" data-webhook-events-error>{{ errors.events[0] }}</p>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    <div v-for="group in groups" :key="group.key" class="rounded-lg border border-line p-3" :data-webhook-group="group.key">
                        <UiCheckbox
                            :model-value="groupState(form.events, group) === 'all'"
                            :indeterminate="groupState(form.events, group) === 'some'"
                            :label="group.name"
                            :disabled="!group.allowed"
                            data-webhook-group-all
                            @update:model-value="chooseGroup(group, $event === true)"
                        />
                        <div class="mt-2 space-y-2 border-t border-line pt-2">
                            <UiCheckbox v-for="event in group.events" :key="event.key" v-model="form.events" :value="event.key" :label="event.name" :disabled="!group.allowed" :data-webhook-event="event.key" />
                        </div>
                        <p v-if="!group.allowed" class="mt-2 text-xs text-muted" data-webhook-group-beyond>{{ t('webhooks.groupBeyond') }}</p>
                    </div>
                </div>
            </fieldset>
        </form>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
            <UiButton type="submit" form="webhook-form" :loading="busy">{{ t('common.save') }}</UiButton>
        </template>
    </UiDialog>
</template>
