<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Lock, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-vue-next';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiPageHeader from '@/components/ui/UiPageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { http, toApiError } from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import type { Role } from '@/types';

const { t, te } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { confirm } = useConfirm();

const roles = ref<Role[]>([]);
const registry = ref<Record<string, string[]>>({});
const open = ref(false);
const editing = ref<Role | null>(null);
const form = reactive({ name: '', permissions: [] as string[] });
const errors = ref<Record<string, string[]>>({});
const busy = ref(false);

const abilities = computed(() => [...new Set(Object.values(registry.value).flat())]);

async function load(): Promise<void> {
    const [r, p] = await Promise.all([http.get('/roles'), http.get('/permissions')]);
    roles.value = r.data.data;
    registry.value = p.data.data;
}
onMounted(load);

function label(group: 'modules' | 'abilities', key: string): string {
    return te(`roles.${group}.${key}`) ? t(`roles.${group}.${key}`) : key;
}

function edit(role: Role | null): void {
    editing.value = role;
    form.name = role?.name ?? '';
    form.permissions = [...(role?.permissions ?? [])];
    errors.value = {};
    open.value = true;
}

function toggleModule(module: string): void {
    const names = registry.value[module].map((a) => `${module}.${a}`);
    const all = names.every((n) => form.permissions.includes(n));
    form.permissions = all ? form.permissions.filter((p) => !names.includes(p)) : [...new Set([...form.permissions, ...names])];
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        if (editing.value) {
            await http.put(`/roles/${editing.value.id}`, form);
        } else {
            await http.post('/roles', form);
        }
        ui.notify(t('common.saved'));
        open.value = false;
        await load();
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

async function remove(role: Role): Promise<void> {
    if (!(await confirm({ title: t('roles.deleteConfirm', { name: role.name }), confirmLabel: t('common.delete'), danger: true }))) {
        return;
    }
    try {
        await http.delete(`/roles/${role.id}`);
        ui.notify(t('common.deleted'));
        await load();
    } catch (error) {
        ui.notify(toApiError(error).message, 'error');
    }
}
</script>

<template>
    <div>
        <UiPageHeader :title="t('roles.title')" :subtitle="t('roles.subtitle')">
            <UiButton v-if="auth.can('roles.create')" @click="edit(null)"><Plus class="size-4" />{{ t('roles.new') }}</UiButton>
        </UiPageHeader>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="role in roles" :key="role.id" class="flex flex-col rounded-xl border border-line bg-surface p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded-lg bg-brand/10 text-brand"><ShieldCheck class="size-5" /></span>
                        <div>
                            <h3 class="font-semibold text-fg">{{ role.name }}</h3>
                            <p class="text-xs text-muted">{{ t('roles.usersCount', { count: role.users_count ?? 0 }) }}</p>
                        </div>
                    </div>
                    <UiBadge v-if="role.is_system" tone="warning"><Lock class="size-3" />{{ t('roles.system') }}</UiBadge>
                </div>
                <div class="mt-4 flex flex-1 flex-wrap content-start gap-1">
                    <UiBadge v-for="p in role.permissions" :key="p">{{ p }}</UiBadge>
                </div>
                <div v-if="!role.is_system" class="mt-4 flex gap-2 border-t border-line pt-4">
                    <UiButton v-if="auth.can('roles.update')" size="sm" variant="secondary" @click="edit(role)"><Pencil class="size-3.5" />{{ t('common.edit') }}</UiButton>
                    <UiButton v-if="auth.can('roles.delete')" size="sm" variant="ghost" @click="remove(role)"><Trash2 class="size-3.5" />{{ t('common.delete') }}</UiButton>
                </div>
            </article>
        </div>

        <UiDialog v-model:open="open" :title="editing ? editing.name : t('roles.new')" size="lg">
            <form id="role-form" class="space-y-5" @submit.prevent="save">
                <UiInput v-model="form.name" :label="t('roles.name')" required :error="errors.name" />
                <div>
                    <p class="mb-2 text-sm font-medium text-fg">{{ t('roles.permissions') }}</p>
                    <div class="overflow-x-auto rounded-lg border border-line">
                        <table class="w-full text-sm">
                            <thead class="bg-surface-2 text-xs text-muted">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium"></th>
                                    <th v-for="a in abilities" :key="a" class="px-3 py-2 text-center font-medium">{{ label('abilities', a) }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(moduleAbilities, module) in registry" :key="module" class="border-t border-line">
                                    <td class="px-4 py-2.5">
                                        <button type="button" class="font-medium text-fg hover:text-brand" @click="toggleModule(String(module))">{{ label('modules', String(module)) }}</button>
                                    </td>
                                    <td v-for="a in abilities" :key="a" class="px-3 py-2.5 text-center">
                                        <input
                                            v-if="moduleAbilities.includes(a)"
                                            v-model="form.permissions"
                                            type="checkbox"
                                            :value="`${module}.${a}`"
                                            class="size-4 accent-[var(--brand)]"
                                            :aria-label="`${module}.${a}`"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
            <template #footer>
                <UiButton variant="secondary" @click="open = false">{{ t('common.cancel') }}</UiButton>
                <UiButton type="submit" form="role-form" :loading="busy">{{ t('common.save') }}</UiButton>
            </template>
        </UiDialog>
    </div>
</template>
