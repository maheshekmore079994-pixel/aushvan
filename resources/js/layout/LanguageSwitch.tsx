import { clsx } from 'clsx';
import { Languages } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { LANGUAGES, setLanguage, type LanguageCode } from '@/lib/i18n';

type Props = {
    /** Lets the signed-in app also save the choice to the user. */
    onChange?: (code: LanguageCode) => void;
    /** A small drop-down for the top bar, where three buttons would not fit on a phone. */
    compact?: boolean;
};

/** English | मराठी | हिंदी. */
export function LanguageSwitch({ onChange, compact }: Props) {
    const { i18n, t } = useTranslation();

    const choose = (code: LanguageCode) => {
        setLanguage(code);
        onChange?.(code);
    };

    if (compact) {
        return (
            <label className="relative flex min-h-9 w-full items-center gap-2 rounded-lg border border-line bg-white pl-2.5 pr-1 text-ink-soft">
                <Languages className="size-4 shrink-0" aria-hidden />
                <span className="sr-only">{t('common.language')}</span>
                <select
                    value={i18n.language}
                    onChange={(e) => choose(e.target.value as LanguageCode)}
                    className="min-h-8 w-full cursor-pointer bg-transparent pr-1 text-sm font-medium text-ink focus:outline-none"
                >
                    {LANGUAGES.map((language) => (
                        <option key={language.code} value={language.code}>
                            {language.label}
                        </option>
                    ))}
                </select>
            </label>
        );
    }

    return (
        <div className="inline-flex overflow-hidden rounded-lg border border-line bg-white shadow-xs" role="group" aria-label={t('common.language')}>
            {LANGUAGES.map((language) => (
                <button
                    key={language.code}
                    type="button"
                    aria-pressed={i18n.language === language.code}
                    onClick={() => choose(language.code)}
                    className={clsx(
                        'min-h-11 px-4 font-semibold transition-colors',
                        i18n.language === language.code ? 'bg-primary text-white' : 'text-ink-soft hover:bg-primary-soft/50',
                    )}
                >
                    {language.label}
                </button>
            ))}
        </div>
    );
}
