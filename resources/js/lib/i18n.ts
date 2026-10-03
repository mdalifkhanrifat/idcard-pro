import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import bn from '@/locales/bn.json';

export type Locale = 'en' | 'bn';
export const LOCALES: { code: Locale; label: string }[] = [
    { code: 'en', label: 'English' },
    { code: 'bn', label: 'বাংলা' },
];

function initialLocale(): Locale {
    try {
        const saved = localStorage.getItem('locale');
        if (saved === 'en' || saved === 'bn') {
            return saved;
        }
    } catch {
        /* storage unavailable */
    }

    return navigator.language.startsWith('bn') ? 'bn' : 'en';
}

export const i18n = createI18n({
    legacy: false,
    locale: initialLocale(),
    fallbackLocale: 'en',
    messages: { en, bn },
});

export function setLocale(locale: string): void {
    const value: Locale = locale === 'bn' ? 'bn' : 'en';
    i18n.global.locale.value = value;
    document.documentElement.lang = value;
    try {
        localStorage.setItem('locale', value);
    } catch {
        /* storage unavailable */
    }
}

document.documentElement.lang = i18n.global.locale.value;
