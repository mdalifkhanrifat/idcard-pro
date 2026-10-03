import { defineStore } from 'pinia';
import { ref } from 'vue';

export type Theme = 'light' | 'dark';

export interface Toast {
    id: number;
    type: 'success' | 'error' | 'info';
    message: string;
}

export const useUiStore = defineStore('ui', () => {
    const theme = ref<Theme>(document.documentElement.classList.contains('dark') ? 'dark' : 'light');
    const sidebarOpen = ref(false);
    const toasts = ref<Toast[]>([]);
    let nextId = 1;

    function setTheme(value: Theme): void {
        theme.value = value;
        document.documentElement.classList.toggle('dark', value === 'dark');
        try {
            localStorage.setItem('theme', value);
        } catch {
            /* storage unavailable */
        }
    }

    function toggleTheme(): void {
        setTheme(theme.value === 'dark' ? 'light' : 'dark');
    }

    function notify(message: string, type: Toast['type'] = 'success'): void {
        const id = nextId++;
        toasts.value.push({ id, type, message });
        setTimeout(() => dismiss(id), 4500);
    }

    function dismiss(id: number): void {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }

    return { theme, sidebarOpen, toasts, setTheme, toggleTheme, notify, dismiss };
});
