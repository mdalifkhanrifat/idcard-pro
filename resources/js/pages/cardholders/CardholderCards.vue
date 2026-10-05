<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { CreditCard, Plus } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiEmpty from '@/components/ui/UiEmpty.vue';
import CardDetailDialog from '@/pages/cards/CardDetailDialog.vue';
import CardRender from '@/pages/cards/CardRender.vue';
import IssueCardDialog from '@/pages/cards/IssueCardDialog.vue';
import { displayStatus, statusTone, useCardReasons } from '@/pages/cards/cards';
import { http } from '@/lib/http';
import { formatDate } from '@/lib/format';
import { useAuthStore } from '@/stores/auth';
import type { CardLayout, CardValues, Cardholder, IssuedCard } from '@/types';

/**
 * Cards of a cardholder and their family members (legacy print screen and print history): the current
 * card as it prints, every card ever issued, and issuing a new one.
 */
const props = defineProps<{ cardholder: Pick<Cardholder, 'id' | 'full_name'> }>();
const { t, te } = useI18n();
const auth = useAuthStore();
useCardReasons();

const cards = ref<IssuedCard[]>([]);
const loading = ref(true);
const current = ref<{ card: IssuedCard; layout: CardLayout; values: CardValues } | null>(null);
const issueOpen = ref(false);
const detailOpen = ref(false);
const detailId = ref<string | null>(null);

const reasonLabel = (key: string) => (te(`cards.reasons.${key}`) ? t(`cards.reasons.${key}`) : key);
/** The cardholder's own active card (family cards are listed below). */
const activeOwn = computed(() => cards.value.find((card) => card.status === 'active' && !card.family_member) ?? null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        cards.value = (await http.get(`/cardholders/${props.cardholder.id}/cards`)).data.data;
        if (activeOwn.value) {
            const { data } = await http.get(`/cards/${activeOwn.value.id}`);
            current.value = { card: data.data, ...data.meta };
        } else {
            current.value = null;
        }
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function show(id: string): void {
    detailId.value = id;
    detailOpen.value = true;
}
</script>

<template>
    <div class="space-y-6" data-cardholder-cards>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-muted">{{ t('cards.tabHint') }}</p>
            <UiButton v-if="auth.can('cards.issue')" data-issue-card @click="issueOpen = true"><Plus class="size-4" />{{ t('cards.issue') }}</UiButton>
        </div>

        <!-- Current card as it prints -->
        <section v-if="current" class="rounded-xl border border-line bg-surface p-5">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <CreditCard class="size-4 text-muted" />
                <h3 class="font-semibold text-fg">{{ t('cards.currentCard') }}</h3>
                <span class="font-mono text-sm text-muted">{{ current.card.number }}</span>
                <UiBadge :tone="statusTone(displayStatus(current.card))">{{ t(`cards.statuses.${displayStatus(current.card)}`) }}</UiBadge>
            </div>
            <button type="button" class="block text-left" :title="t('cards.open')" @click="show(current.card.id)">
                <CardRender :layout="current.layout" :values="current.values" :box="300" />
            </button>
        </section>

        <!-- History -->
        <section class="rounded-xl border border-line bg-surface">
            <h3 class="border-b border-line px-5 py-3 font-semibold text-fg">{{ t('cards.history') }}</h3>
            <div v-if="cards.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs font-medium tracking-wide whitespace-nowrap text-muted uppercase">
                        <tr class="border-b border-line">
                            <th class="px-5 py-3">{{ t('cards.number') }}</th>
                            <th class="px-5 py-3">{{ t('cards.holder') }}</th>
                            <th class="px-5 py-3">{{ t('cards.template') }}</th>
                            <th class="px-5 py-3">{{ t('cards.reason') }}</th>
                            <th class="px-5 py-3">{{ t('cards.validity') }}</th>
                            <th class="px-5 py-3">{{ t('common.status') }}</th>
                            <th class="px-5 py-3">{{ t('cards.issuedBy') }}</th>
                        </tr>
                    </thead>
                    <tbody :class="loading && 'opacity-50'">
                        <tr v-for="card in cards" :key="card.id" class="cursor-pointer border-b border-line last:border-0 hover:bg-surface-2" data-card-row @click="show(card.id)">
                            <td class="px-5 py-3 font-mono whitespace-nowrap text-fg">{{ card.number }}</td>
                            <td class="px-5 py-3 text-fg">{{ card.family_member ? card.family_member.name : t('cards.self') }}</td>
                            <td class="px-5 py-3 text-muted">{{ card.template?.name }}</td>
                            <td class="px-5 py-3 text-muted">
                                {{ reasonLabel(card.reason) }}
                                <p v-if="card.notes" class="max-w-56 truncate text-xs" :title="card.notes">{{ card.notes }}</p>
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap text-muted">{{ formatDate(card.issue_date) }} – {{ card.expiry_date ? formatDate(card.expiry_date) : t('cards.noExpiry') }}</td>
                            <td class="px-5 py-3"><UiBadge :tone="statusTone(displayStatus(card))">{{ t(`cards.statuses.${displayStatus(card)}`) }}</UiBadge></td>
                            <td class="px-5 py-3 whitespace-nowrap text-muted">{{ card.issued_by ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <UiEmpty v-else-if="!loading" :title="t('cards.empty')" :hint="auth.can('cards.issue') ? t('cards.emptyHint') : undefined" />
        </section>

        <IssueCardDialog v-model:open="issueOpen" :cardholder="cardholder" @issued="load" />
        <CardDetailDialog v-model:open="detailOpen" :card-id="detailId" @changed="load" />
    </div>
</template>
