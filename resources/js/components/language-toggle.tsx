import { useTranslation } from '@/lib/i18n/context';

export default function LanguageToggle() {
    const { locale, setLocale } = useTranslation();

    return (
        <div className="flex overflow-hidden rounded-none border border-gray-300 text-xs font-semibold">
            <button
                type="button"
                onClick={() => setLocale('lv')}
                aria-pressed={locale === 'lv'}
                className={`px-2 py-1 transition ${
                    locale === 'lv'
                        ? 'bg-yellow-400 text-black'
                        : 'bg-white text-gray-500 hover:bg-gray-50'
                }`}
            >
                LV
            </button>
            <button
                type="button"
                onClick={() => setLocale('en')}
                aria-pressed={locale === 'en'}
                className={`border-l border-gray-300 px-2 py-1 transition ${
                    locale === 'en'
                        ? 'bg-yellow-400 text-black'
                        : 'bg-white text-gray-500 hover:bg-gray-50'
                }`}
            >
                EN
            </button>
        </div>
    );
}
