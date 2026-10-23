<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { CalendarDays, Loader2, SearchX } from 'lucide-vue-next';
import LocaleThemeSwitch from '@/components/LocaleThemeSwitch.vue';
import { brandTokens } from '@/lib/brand';
import { toApiError } from '@/lib/http';
import { currentPerson, fetchPublicInfo, type PublicBookingInfo, type PublicPerson } from '@/lib/appointments/public';
import PublicHome from './PublicHome.vue';
import PublicIdentify from './PublicIdentify.vue';

/**
 * The public booking link (5.6, /book/<organization>): the organization's own page — its name, logo and colour —
 * where a person proves who they are (employee number, then the organization's method) and books, sees, moves and
 * cancels their own appointments as the organization allows. Not for search engines; the session lasts for the tab.
 */
const { t } = useI18n();
const organization = String(useRoute().params.organization);

const info = ref<PublicBookingInfo | null>(null);
const person = ref<PublicPerson | null>(null);
const loading = ref(true);
const problem = ref<'unavailable' | 'too_many' | 'failed' | null>(null);
/** Why the person was signed out (their session ended), shown on the sign-in step. */
const endedNote = ref<string | null>(null);
const brand = computed(() => brandTokens(info.value?.organization.primary_color ?? undefined));

const robots = document.createElement('meta');
robots.name = 'robots';
robots.content = 'noindex, nofollow';

async function load(): Promise<void> {
    loading.value = true;
    problem.value = null;
    try {
        info.value = await fetchPublicInfo(organization);
        document.title = `${t('publicBooking.title')} · ${info.value.organization.name}`;
        person.value = await currentPerson(organization);
    } catch (error) {
        const status = toApiError(error).status;
        problem.value = status === 404 ? 'unavailable' : status === 429 ? 'too_many' : 'failed';
    } finally {
        loading.value = false;
    }
}
function signedOut(note: string | null): void {
    person.value = null;
    endedNote.value = note;
}
onMounted(() => {
    document.head.appendChild(robots);
    load();
});
onBeforeUnmount(() => robots.remove());
</script>

<template>
    <div class="flex min-h-screen flex-col items-center bg-bg px-4 py-6" data-public-booking>
        <div class="mb-4 flex w-full max-w-2xl justify-end">
            <LocaleThemeSwitch />
        </div>

        <div v-if="loading" class="flex flex-1 items-center gap-2 text-muted"><Loader2 class="size-5 animate-spin" />{{ t('common.loading') }}</div>

        <div v-else-if="problem" class="w-full max-w-md rounded-2xl border border-line bg-surface p-6 text-center" :data-public-problem="problem">
            <SearchX class="mx-auto size-10 text-muted" />
            <h1 class="mt-3 text-lg font-semibold text-fg">{{ t(`publicBooking.problems.${problem}.title`) }}</h1>
            <p class="mt-1 text-sm text-muted">{{ t(`publicBooking.problems.${problem}.hint`) }}</p>
        </div>

        <article v-else-if="info" class="w-full max-w-2xl overflow-hidden rounded-2xl border border-line bg-surface shadow-sm" data-brand-scope :style="brand">
            <header class="flex items-center gap-3 bg-brand px-5 py-4 text-brand-contrast">
                <img v-if="info.organization.logo_url" :src="info.organization.logo_url" alt="" class="size-10 rounded-lg bg-white/90 object-contain p-0.5" />
                <CalendarDays v-else class="size-8" />
                <div class="min-w-0">
                    <p class="truncate font-semibold" data-public-organization>{{ info.organization.name }}</p>
                    <p class="text-xs opacity-80">{{ t('publicBooking.title') }}</p>
                </div>
            </header>
            <div class="p-5">
                <PublicHome v-if="person" :organization="organization" :info="info" :person="person" @signed-out="signedOut" />
                <PublicIdentify v-else :organization="organization" :info="info" :note="endedNote" @signed-in="(who) => { person = who; endedNote = null; }" />
            </div>
        </article>

        <p class="mt-6 max-w-md text-center text-xs text-muted">{{ t('publicBooking.footer') }}</p>
    </div>
</template>
