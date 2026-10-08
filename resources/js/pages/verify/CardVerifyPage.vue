<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { BadgeCheck, Loader2, SearchX, ShieldAlert, ShieldCheck, ShieldX } from 'lucide-vue-next';
import LocaleThemeSwitch from '@/components/LocaleThemeSwitch.vue';
import { verificationFieldLabel } from '@/pages/cards/cards';
import { brandTokens } from '@/lib/brand';
import { formatDate, formatDateTime } from '@/lib/format';
import { http, toApiError } from '@/lib/http';

/**
 * Public card verification (3.7), opened from a card's QR code without signing in: whether the card is
 * valid, in the organization's colours, with only the details the organization chose to show.
 */
interface Verification {
    status: 'valid' | 'expired' | 'revoked' | 'replaced';
    status_changed_at: string | null;
    revoke_reason: string | null;
    holder: 'cardholder' | 'family';
    photo_url: string | null;
    details: { key: string; value: string }[];
    organization: { name: string; logo_url: string | null; primary_color: string };
    note: string | null;
    checked_at: string;
}

const route = useRoute();
const { t, te } = useI18n();

const result = ref<Verification | null>(null);
const problem = ref<'not_found' | 'too_many' | 'failed' | null>(null);
const loading = ref(true);

const STATUS = {
    valid: { icon: ShieldCheck, tone: 'text-success', panel: 'border-success/40 bg-success/10' },
    expired: { icon: ShieldAlert, tone: 'text-warning', panel: 'border-warning/40 bg-warning/10' },
    revoked: { icon: ShieldX, tone: 'text-danger', panel: 'border-danger/40 bg-danger/10' },
    replaced: { icon: ShieldX, tone: 'text-danger', panel: 'border-danger/40 bg-danger/10' },
} as const;

const status = computed(() => (result.value ? STATUS[result.value.status] : null));
const brand = computed(() => brandTokens(result.value?.organization.primary_color));
/** On a family member's card, the cardholder's name is whose family it is. */
const label = (key: string) => (key === 'full_name' && result.value?.holder === 'family' ? t('verify.familyOf') : verificationFieldLabel(key, t, te));

// Card pages are not for search engines
const robots = document.createElement('meta');
robots.name = 'robots';
robots.content = 'noindex, nofollow';

onMounted(async () => {
    document.head.appendChild(robots);
    try {
        result.value = (await http.get<{ data: Verification }>(`/verify/${encodeURIComponent(String(route.params.code))}`)).data.data;
        document.title = `${t('verify.title')} · ${result.value.organization.name}`;
    } catch (error) {
        const status = toApiError(error).status;
        problem.value = status === 404 ? 'not_found' : status === 429 ? 'too_many' : 'failed';
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => robots.remove());
</script>

<template>
    <div class="flex min-h-screen flex-col items-center bg-bg px-4 py-8" data-card-verify>
        <div class="mb-4 flex w-full max-w-md justify-end">
            <LocaleThemeSwitch />
        </div>

        <div v-if="loading" class="flex flex-1 items-center gap-2 text-muted"><Loader2 class="size-5 animate-spin" />{{ t('verify.checking') }}</div>

        <!-- Unknown code, verification off, too many checks -->
        <div v-else-if="problem" class="w-full max-w-md rounded-2xl border border-line bg-surface p-6 text-center" :data-verify-problem="problem">
            <SearchX class="mx-auto size-10 text-muted" />
            <h1 class="mt-3 text-lg font-semibold text-fg">{{ t(`verify.problems.${problem}.title`) }}</h1>
            <p class="mt-1 text-sm text-muted">{{ t(`verify.problems.${problem}.hint`) }}</p>
        </div>

        <article v-else-if="result && status" class="w-full max-w-md overflow-hidden rounded-2xl border border-line bg-surface shadow-sm" data-brand-scope :style="brand" :data-verify-status="result.status">
            <header class="flex items-center gap-3 bg-brand px-5 py-4 text-brand-contrast">
                <img v-if="result.organization.logo_url" :src="result.organization.logo_url" alt="" class="size-10 rounded-lg bg-white/90 object-contain p-0.5" />
                <BadgeCheck v-else class="size-8" />
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ result.organization.name }}</p>
                    <p class="text-xs opacity-80">{{ t('verify.title') }}</p>
                </div>
            </header>

            <div class="space-y-5 p-5">
                <div class="flex items-start gap-3 rounded-xl border p-4" :class="status.panel" data-verify-result>
                    <component :is="status.icon" class="size-9 shrink-0" :class="status.tone" />
                    <div class="min-w-0">
                        <h1 class="text-lg font-semibold" :class="status.tone">{{ t(`verify.status.${result.status}.title`) }}</h1>
                        <p class="text-sm text-fg">{{ t(`verify.status.${result.status}.hint`) }}</p>
                        <p v-if="result.status_changed_at" class="mt-1 text-xs text-muted">{{ t('verify.since', { date: formatDate(result.status_changed_at) }) }}</p>
                        <p v-if="result.revoke_reason" class="mt-1 text-sm text-fg" data-verify-reason>{{ t('verify.reason') }}: {{ result.revoke_reason }}</p>
                    </div>
                </div>

                <div v-if="result.photo_url || result.details.length" class="flex gap-4">
                    <img v-if="result.photo_url" :src="result.photo_url" alt="" class="h-28 w-22 shrink-0 rounded-lg border border-line object-cover" data-verify-photo />
                    <dl class="min-w-0 flex-1 space-y-2 text-sm" data-verify-details>
                        <div v-for="detail in result.details" :key="detail.key">
                            <dt class="text-xs text-muted">{{ label(detail.key) }}</dt>
                            <dd class="break-words font-medium text-fg" :data-detail="detail.key">{{ detail.value }}</dd>
                        </div>
                    </dl>
                </div>

                <p v-if="result.note" class="rounded-lg bg-surface-2 p-3 text-sm whitespace-pre-line text-fg" data-verify-note>{{ result.note }}</p>

                <p class="border-t border-line pt-3 text-xs text-muted">{{ t('verify.checkedAt', { time: formatDateTime(result.checked_at) }) }}</p>
            </div>
        </article>

        <p class="mt-6 text-xs text-muted">{{ t('verify.footer') }}</p>
    </div>
</template>
