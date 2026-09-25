import {
    createContext,
    PropsWithChildren,
    useCallback,
    useContext,
    useMemo,
    useState,
} from 'react';
import en, { TranslationKey } from './en';
import lv from './lv';

export type Locale = 'en' | 'lv';

const DEFAULT_LOCALE: Locale = 'lv';
const COOKIE_NAME = 'locale';

const dictionaries: Record<Locale, Record<TranslationKey, string>> = {
    en,
    lv,
};

// The Intl-formatting equivalent of each locale, for toLocaleDateString()
// etc. — passing this explicitly (instead of the browser's own default)
// keeps dates/times in step with the chosen language rather than whatever
// the visitor's OS happens to be set to.
const INTL_LOCALE: Record<Locale, string> = {
    en: 'en-US',
    lv: 'lv-LV',
};

function readCookieLocale(): Locale | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(
        new RegExp(`(?:^|; )${COOKIE_NAME}=(en|lv)(?:;|$)`),
    );

    return match ? (match[1] as Locale) : null;
}

function writeCookieLocale(locale: Locale): void {
    // 1 year, available site-wide — a plain client-side preference, not
    // anything the server needs to read.
    document.cookie = `${COOKIE_NAME}=${locale}; path=/; max-age=31536000; samesite=lax`;
}

type TranslationContextValue = {
    locale: Locale;
    intlLocale: string;
    setLocale: (locale: Locale) => void;
    t: (
        key: TranslationKey,
        params?: Record<string, string | number>,
    ) => string;
    /**
     * Picks `${key}_one` when count === 1, `${key}_other` otherwise, and
     * always makes {count} available to the string — English and Latvian
     * both only really need this one/other split for the strings that use
     * it here (nothing in this app's copy hinges on Latvian's further
     * numeric-ending rules, e.g. 21 vs 25).
     */
    tCount: (
        key: string,
        count: number,
        params?: Record<string, string | number>,
    ) => string;
};

const TranslationContext = createContext<TranslationContextValue | null>(null);

function interpolate(
    template: string,
    params?: Record<string, string | number>,
): string {
    if (!params) {
        return template;
    }

    return template.replace(/\{(\w+)\}/g, (match, key) =>
        key in params ? String(params[key]) : match,
    );
}

export function LocaleProvider({ children }: PropsWithChildren) {
    const [locale, setLocaleState] = useState<Locale>(
        () => readCookieLocale() ?? DEFAULT_LOCALE,
    );

    const setLocale = useCallback((next: Locale) => {
        setLocaleState(next);
        writeCookieLocale(next);
    }, []);

    const t = useCallback(
        (key: TranslationKey, params?: Record<string, string | number>) =>
            interpolate(dictionaries[locale][key], params),
        [locale],
    );

    const tCount = useCallback(
        (
            key: string,
            count: number,
            params?: Record<string, string | number>,
        ) => {
            const variant = (
                count === 1 ? `${key}_one` : `${key}_other`
            ) as TranslationKey;

            return interpolate(dictionaries[locale][variant], {
                count,
                ...params,
            });
        },
        [locale],
    );

    const value = useMemo(
        () => ({
            locale,
            intlLocale: INTL_LOCALE[locale],
            setLocale,
            t,
            tCount,
        }),
        [locale, setLocale, t, tCount],
    );

    return (
        <TranslationContext.Provider value={value}>
            {children}
        </TranslationContext.Provider>
    );
}

export function useTranslation(): TranslationContextValue {
    const context = useContext(TranslationContext);

    if (!context) {
        throw new Error(
            'useTranslation() must be used within a LocaleProvider',
        );
    }

    return context;
}
