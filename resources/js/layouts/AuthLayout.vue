<script setup lang="ts">
import { BadgeCheck, Fingerprint, ShieldCheck } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import LocaleThemeSwitch from '@/components/LocaleThemeSwitch.vue';

const { t } = useI18n();
</script>

<template>
    <div class="grid min-h-full lg:grid-cols-[1.1fr_1fr]">
        <aside class="relative hidden overflow-hidden bg-[#0b1220] p-12 text-white lg:flex lg:flex-col lg:justify-between">
            <div
                class="pointer-events-none absolute -top-40 -left-40 size-[36rem] rounded-full opacity-40 blur-3xl"
                style="background: radial-gradient(circle, var(--brand), transparent 60%)"
            />
            <div class="relative flex items-center gap-3 text-lg font-semibold">
                <div class="flex size-9 items-center justify-center rounded-lg bg-white/10"><BadgeCheck class="size-5" /></div>
                {{ t('app.name') }}
            </div>

            <div class="relative">
                <!-- Sample ID card illustration -->
                <div class="mb-10 w-[340px] rotate-[-4deg] rounded-2xl bg-gradient-to-br from-white to-slate-200 p-5 text-slate-900 shadow-2xl">
                    <div class="mb-4 flex items-center justify-between">
                        <span class="text-xs font-semibold tracking-widest text-slate-500 uppercase">Employee ID</span>
                        <span class="h-2 w-10 rounded-full" style="background: var(--brand)" />
                    </div>
                    <div class="flex gap-4">
                        <div class="h-24 w-20 rounded-lg bg-slate-300" />
                        <div class="flex-1 space-y-2 pt-1">
                            <div class="h-3 w-32 rounded bg-slate-800" />
                            <div class="h-2.5 w-24 rounded bg-slate-400" />
                            <div class="h-2.5 w-28 rounded bg-slate-400" />
                            <div class="mt-3 flex items-end justify-between">
                                <div class="font-mono text-[11px] text-slate-500">EMP/26000142</div>
                                <div class="grid size-10 grid-cols-4 gap-px rounded bg-white p-1">
                                    <span v-for="i in 16" :key="i" :class="i % 3 ? 'bg-slate-900' : 'bg-white'" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <h2 class="max-w-md text-3xl leading-tight font-semibold">{{ t('app.tagline') }}</h2>
                <ul class="mt-6 space-y-3 text-sm text-white/70">
                    <li class="flex items-center gap-2"><ShieldCheck class="size-4" /> SSO-ready · MFA · Audit trail</li>
                    <li class="flex items-center gap-2"><Fingerprint class="size-4" /> Biometric enrollment</li>
                </ul>
            </div>
            <p class="relative text-xs text-white/40">© {{ new Date().getFullYear() }} {{ t('app.name') }}</p>
        </aside>

        <main class="flex flex-col">
            <div class="flex justify-end p-4"><LocaleThemeSwitch /></div>
            <div class="flex flex-1 items-center justify-center px-6 pb-16">
                <div class="w-full max-w-sm"><RouterView /></div>
            </div>
        </main>
    </div>
</template>
