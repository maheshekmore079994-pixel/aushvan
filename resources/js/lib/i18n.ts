import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import en from '@/locales/en.json';
import hi from '@/locales/hi.json';
import mr from '@/locales/mr.json';

export const LANGUAGES = [
    { code: 'en', label: 'English' },
    { code: 'mr', label: 'मराठी' },
    { code: 'hi', label: 'हिंदी' },
] as const;

export type LanguageCode = (typeof LANGUAGES)[number]['code'];

const STORAGE_KEY = 'rmgsms.language';

function remembered(): LanguageCode {
    try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (LANGUAGES.some((l) => l.code === saved)) return saved as LanguageCode;
    } catch {
        // Storage can be unavailable; English is the default.
    }
    return 'en';
}

void i18n.use(initReactI18next).init({
    resources: { en: { translation: en }, mr: { translation: mr }, hi: { translation: hi } },
    lng: remembered(),
    fallbackLng: 'en',
    interpolation: { escapeValue: false },
});

export function setLanguage(code: LanguageCode): void {
    void i18n.changeLanguage(code);
    document.documentElement.lang = code;
    try {
        localStorage.setItem(STORAGE_KEY, code);
    } catch {
        // Not remembered on this PC; still switched for this visit.
    }
}

/** Locale for dates and numbers in the chosen language (Indian formats in all three). */
export function dateLocale(language: string = i18n.language): string {
    // "-u-nu-latn": always 0-9, so dates match the amounts and counts beside them.
    return `${language === 'mr' ? 'mr-IN' : language === 'hi' ? 'hi-IN' : 'en-IN'}-u-nu-latn`;
}

export default i18n;
