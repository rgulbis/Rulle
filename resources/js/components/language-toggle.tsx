import { useTranslation } from '@/lib/i18n/context';
import type { Locale } from '@/lib/i18n/context';

const LOCALES: Locale[] = ['lv', 'en'];

export default function LanguageToggle({
    onDark = false,
}: {
    // On a black panel (the auth pages' left side) the unselected button
    // needs a light outline instead of the usual ink one.
    onDark?: boolean;
}) {
    const { locale, setLocale, t } = useTranslation();

    return (
        <div
            role="group"
            aria-label={t('nav.language')}
            className="flex gap-1 font-mono text-sm font-semibold"
        >
            {LOCALES.map((option) => {
                const active = locale === option;

                return (
                    <button
                        key={option}
                        type="button"
                        onClick={() => setLocale(option)}
                        aria-pressed={active}
                        lang={option}
                        className={`min-h-11 min-w-11 border-2 uppercase transition ${
                            active
                                ? 'border-accent bg-accent text-accent-ink'
                                : onDark
                                  ? 'border-[#6e6d68] text-[#f7f6f2] hover:border-[#f7f6f2]'
                                  : 'border-ink text-ink hover:bg-paper'
                        }`}
                    >
                        {option}
                    </button>
                );
            })}
        </div>
    );
}
