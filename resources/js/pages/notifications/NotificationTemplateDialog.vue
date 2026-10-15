<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Mail, MessageSquareText, RotateCcw, TextCursorInput } from 'lucide-vue-next';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import { formatNumber } from '@/lib/format';
import type { NotificationEventOption, NotificationOptions, NotificationPart, NotificationTexts } from '@/lib/notifications/api';
import { composeMessage, smsParts, unknownPlaceholders } from '@/lib/notifications/text';

/**
 * An event's texts in the organization's words, per language: empty = the event's default (shown greyed), values
 * inserted at the cursor, unknown ones marked, lengths and SMS parts counted, and the message previewed as it
 * will go (sample values, the organization's languages in order). Changes apply to the page's draft; saving is
 * the page's.
 */
const props = defineProps<{
    eventKey: string;
    event: NotificationEventOption;
    templates: Record<string, NotificationTexts>;
    /** The organization's languages in order (as being set on the page), and those an SMS takes. */
    languages: string[];
    smsLanguages: string[];
    options: NotificationOptions;
    samples: Record<string, Record<string, string>>;
    errors: Record<string, string[]>;
    disabled?: boolean;
}>();
const emit = defineEmits<{ apply: [templates: Record<string, NotificationTexts>] }>();
const open = defineModel<boolean>('open', { default: false });
const { t } = useI18n();

const PARTS: NotificationPart[] = ['subject', 'body', 'sms'];
/** The organization's languages first, then the others it could turn on (their texts are kept for then). */
const tabs = computed(() => [...props.languages, ...props.options.languages.filter((language) => !props.languages.includes(language))]);
const language = ref('');
const texts = ref<Record<string, NotificationTexts>>({});

watch(open, (isOpen) => {
    if (isOpen) {
        texts.value = Object.fromEntries(
            props.options.languages.map((key) => [key, { ...{ subject: null, body: null, sms: null }, ...(props.templates[key] as Partial<NotificationTexts> | undefined) }]),
        ) as Record<string, NotificationTexts>;
        language.value = tabs.value[0] ?? '';
    }
}, { immediate: true });

const offered = computed(() => props.event.placeholders.map((placeholder) => placeholder.key));
const defaultText = (part: NotificationPart, inLanguage = language.value) => props.event.default_templates[inLanguage]?.[part] ?? '';
/** What goes: the organization's own text, else the default. */
const effective = (part: NotificationPart, inLanguage = language.value) => texts.value[inLanguage]?.[part]?.trim() ? (texts.value[inLanguage][part] as string) : defaultText(part, inLanguage);
const own = (inLanguage: string) => PARTS.some((part) => !!texts.value[inLanguage]?.[part]?.trim());
const serverError = (part: NotificationPart, inLanguage = language.value) => props.errors[`notifications.events.${props.eventKey}.templates.${inLanguage}.${part}`]?.[0];
const unknown = (part: NotificationPart, inLanguage = language.value) => unknownPlaceholders(texts.value[inLanguage]?.[part] ?? '', offered.value);
/** As written in a text: {card.numbr}, {x} */
const unknownNames = (part: NotificationPart) => unknown(part).map((name) => `{${name}}`).join(', ');
const hasUnknown = computed(() => props.options.languages.some((key) => PARTS.some((part) => unknown(part, key).length)));

/** Where a value goes when its button is pressed: the field last typed in (the body until then). */
const fields = ref<Partial<Record<NotificationPart, HTMLInputElement | HTMLTextAreaElement | null>>>({});
const lastPart = ref<NotificationPart>('body');

async function insert(key: string): Promise<void> {
    if (props.disabled) {
        return;
    }
    const part = lastPart.value;
    const field = fields.value[part];
    const current = texts.value[language.value][part] ?? '';
    const start = field?.selectionStart ?? current.length;
    const end = field?.selectionEnd ?? current.length;
    const token = `{${key}}`;
    texts.value[language.value][part] = current.slice(0, start) + token + current.slice(end);
    await nextTick();
    field?.focus();
    field?.setSelectionRange(start + token.length, start + token.length);
}

function startFromDefault(part: NotificationPart): void {
    texts.value[language.value][part] = defaultText(part);
}

function reset(part: NotificationPart): void {
    texts.value[language.value][part] = null;
}

/** The whole message as it will go, or this language alone while the organization does not send in it. */
const inOrganization = computed(() => props.languages.includes(language.value));
const preview = computed(() => {
    const previewLanguages = inOrganization.value ? props.languages : [language.value];
    const smsLanguages = inOrganization.value ? props.smsLanguages : [language.value];
    const words = Object.fromEntries(previewLanguages.map((key) => [key, { subject: effective('subject', key), body: effective('body', key), sms: effective('sms', key) }]));

    return composeMessage(words, props.samples, previewLanguages, smsLanguages, props.options.separators);
});
const sms = computed(() => smsParts(preview.value.sms));
const partSms = computed(() => smsParts(effective('sms')));

const partLabel = (part: NotificationPart) => t(`notifications.editor.parts.${part}`);
const length = (part: NotificationPart) => (texts.value[language.value]?.[part] ?? '').length;

function apply(): void {
    // Blank = the default (as the server reads it)
    emit('apply', Object.fromEntries(Object.entries(texts.value).map(([key, parts]) => [key, Object.fromEntries(PARTS.map((part) => [part, parts[part]?.trim() ? parts[part] : null]))])) as Record<string, NotificationTexts>);
    open.value = false;
}
</script>

<template>
    <UiDialog v-model:open="open" :title="event.name" :description="event.description" size="2xl" full-on-phone>
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]" data-template-editor>
            <div class="min-w-0 space-y-5">
                <div role="tablist" class="flex gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-line)]">
                    <button
                        v-for="key in tabs"
                        :key="key"
                        type="button"
                        role="tab"
                        :aria-selected="language === key"
                        class="flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm whitespace-nowrap"
                        :class="language === key ? 'border-brand-ink font-medium text-brand-ink' : 'border-transparent text-muted hover:text-fg'"
                        :data-language-tab="key"
                        @click="language = key"
                    >
                        {{ options.labels.languages[key] ?? key }}
                        <span v-if="own(key)" class="size-1.5 rounded-full bg-brand" :title="t('notifications.events.ownTexts')" />
                        <span v-if="!languages.includes(key)" class="text-xs text-muted">({{ t('notifications.editor.off') }})</span>
                    </button>
                </div>

                <p v-if="!inOrganization" class="rounded-lg bg-surface-2 px-3 py-2 text-xs text-muted" data-not-in-organization>{{ t('notifications.editor.notInOrganization') }}</p>

                <div v-if="!disabled" class="space-y-1.5">
                    <p class="flex items-center gap-1.5 text-sm font-medium text-fg"><TextCursorInput class="size-4 text-muted" />{{ t('notifications.editor.insert') }}</p>
                    <div class="flex flex-wrap gap-1.5" data-placeholders>
                        <button
                            v-for="placeholder in event.placeholders"
                            :key="placeholder.key"
                            type="button"
                            class="rounded-full border border-line px-2.5 py-1 text-xs text-fg hover:border-brand-ink hover:text-brand-ink"
                            :title="`{${placeholder.key}}`"
                            :data-placeholder="placeholder.key"
                            @mousedown.prevent
                            @click="insert(placeholder.key)"
                        >
                            {{ placeholder.label }}
                        </button>
                    </div>
                    <p class="text-xs text-muted">{{ t('notifications.editor.insertHint', { part: partLabel(lastPart) }) }}</p>
                </div>

                <div v-for="part in PARTS" :key="`${language}-${part}`" class="space-y-1.5" :data-part="part">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label :for="`text-${part}`" class="text-sm font-medium text-fg">{{ partLabel(part) }}</label>
                        <template v-if="!disabled">
                            <button v-if="texts[language][part]?.trim()" type="button" class="inline-flex items-center gap-1 text-xs text-brand-ink hover:underline" data-reset @click="reset(part)">
                                <RotateCcw class="size-3.5" />{{ t('notifications.editor.reset') }}
                            </button>
                            <button v-else type="button" class="text-xs text-brand-ink hover:underline" data-start @click="startFromDefault(part)">{{ t('notifications.editor.startFromDefault') }}</button>
                        </template>
                    </div>
                    <input
                        v-if="part === 'subject'"
                        :id="`text-${part}`"
                        :ref="(el) => (fields[part] = el as HTMLInputElement | null)"
                        v-model="texts[language][part]"
                        :placeholder="defaultText(part)"
                        :maxlength="options.max_length[part]"
                        :readonly="disabled"
                        class="h-10 w-full rounded-lg border bg-surface px-3 text-sm text-fg placeholder:text-muted/70 focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none read-only:bg-surface-2"
                        :class="unknown(part).length || serverError(part) ? 'border-danger' : 'border-line'"
                        @focus="lastPart = part"
                        @input="lastPart = part"
                    />
                    <textarea
                        v-else
                        :id="`text-${part}`"
                        :ref="(el) => (fields[part] = el as HTMLTextAreaElement | null)"
                        v-model="texts[language][part]"
                        :rows="part === 'body' ? 7 : 3"
                        :placeholder="defaultText(part)"
                        :maxlength="options.max_length[part]"
                        :readonly="disabled"
                        class="w-full rounded-lg border bg-surface px-3 py-2 text-sm text-fg placeholder:text-muted/70 focus:border-brand-ink focus:ring-4 focus:ring-[var(--ring)] focus:outline-none read-only:bg-surface-2"
                        :class="unknown(part).length || serverError(part) ? 'border-danger' : 'border-line'"
                        @focus="lastPart = part"
                        @input="lastPart = part"
                    />
                    <div class="flex flex-wrap justify-between gap-x-3 gap-y-1 text-xs">
                        <p v-if="unknown(part).length" class="text-danger" data-unknown>{{ t('notifications.editor.unknown', { names: unknownNames(part) }) }}</p>
                        <p v-else-if="serverError(part)" class="text-danger">{{ serverError(part) }}</p>
                        <p v-else class="text-muted">{{ texts[language][part]?.trim() ? t('notifications.editor.own') : t('notifications.editor.default') }}</p>
                        <p class="text-muted" :data-count="part">
                            <template v-if="part === 'sms'">{{ t('notifications.editor.smsCount', { length: formatNumber(partSms.length), parts: formatNumber(partSms.parts) }) }} · {{ t(`notifications.editor.encoding.${partSms.encoding}`) }}</template>
                            <template v-else>{{ formatNumber(length(part)) }} / {{ formatNumber(options.max_length[part]) }}</template>
                        </p>
                    </div>
                </div>
            </div>

            <aside class="min-w-0 space-y-4 lg:sticky lg:top-0 lg:self-start" data-preview>
                <div>
                    <p class="text-sm font-medium text-fg">{{ t('notifications.editor.preview') }}</p>
                    <p class="text-xs text-muted">{{ inOrganization ? t('notifications.editor.previewHint') : t('notifications.editor.previewLanguage') }}</p>
                </div>
                <div class="overflow-hidden rounded-xl border border-line" data-preview-mail>
                    <p class="flex items-center gap-1.5 border-b border-line bg-surface-2 px-3 py-2 text-xs text-muted"><Mail class="size-3.5" />{{ t('notifications.editor.mail') }}</p>
                    <div class="space-y-2 p-3 text-sm">
                        <p class="font-semibold break-words text-fg" data-preview-subject>{{ preview.subject }}</p>
                        <p class="break-words whitespace-pre-line text-fg" data-preview-body>{{ preview.body }}</p>
                    </div>
                </div>
                <div class="rounded-xl border border-line" data-preview-sms>
                    <p class="flex items-center gap-1.5 border-b border-line bg-surface-2 px-3 py-2 text-xs text-muted"><MessageSquareText class="size-3.5" />{{ t('notifications.editor.sms') }}</p>
                    <div class="p-3">
                        <p class="max-w-[85%] rounded-2xl rounded-bl-sm bg-brand/12 px-3 py-2 text-sm break-words whitespace-pre-line text-fg" data-preview-sms-text>{{ preview.sms }}</p>
                        <p class="mt-2 text-xs" :class="sms.parts > 1 ? 'text-warning' : 'text-muted'" data-preview-sms-count>
                            {{ t('notifications.editor.smsCount', { length: formatNumber(sms.length), parts: formatNumber(sms.parts) }) }} · {{ t(`notifications.editor.encoding.${sms.encoding}`) }}
                        </p>
                    </div>
                </div>
                <p class="text-xs text-muted">{{ t('notifications.editor.samples') }}</p>
            </aside>
        </div>

        <template #footer>
            <template v-if="disabled">
                <UiButton variant="secondary" @click="open = false">{{ t('common.close') }}</UiButton>
            </template>
            <template v-else>
                <p v-if="hasUnknown" class="mr-auto self-center text-xs text-danger">{{ t('notifications.editor.fixUnknown') }}</p>
                <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
                <UiButton :disabled="hasUnknown" data-apply @click="apply">{{ t('notifications.editor.apply') }}</UiButton>
            </template>
        </template>
    </UiDialog>
</template>
