import { CheckIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';
import type { TranslationKey } from '@/lib/i18n/en';

// Mirrors Password::defaults() in AppServiceProvider (min 10, mixed case,
// a number, a symbol) so people see what's required before submitting.
// The server still validates — including the "not in a data leak" check,
// which can only happen there.
const RULES: { key: TranslationKey; test: (password: string) => boolean }[] = [
    { key: 'form.rule.length', test: (p) => p.length >= 10 },
    {
        key: 'form.rule.mixedCase',
        test: (p) => /\p{Ll}/u.test(p) && /\p{Lu}/u.test(p),
    },
    { key: 'form.rule.number', test: (p) => /\p{N}/u.test(p) },
    { key: 'form.rule.symbol', test: (p) => /[^\p{L}\p{N}]/u.test(p) },
];

export default function PasswordChecklist({
    id,
    password,
}: {
    id?: string;
    password: string;
}) {
    const { t } = useTranslation();

    return (
        <ul
            id={id}
            className="grid grid-cols-2 gap-x-3 gap-y-1.5 text-[13px] sm:gap-x-4 sm:text-sm"
        >
            {RULES.map((rule) => {
                const met = rule.test(password);

                return (
                    <li
                        key={rule.key}
                        className={`flex items-center gap-2 ${met ? 'text-ink font-semibold' : 'text-muted'}`}
                    >
                        {met ? (
                            <span className="bg-ink text-accent flex size-[18px] items-center justify-center">
                                <CheckIcon size={12} strokeWidth={4} />
                            </span>
                        ) : (
                            <span
                                aria-hidden="true"
                                className="border-faint size-[18px] border-2"
                            />
                        )}
                        <span>
                            {t(rule.key)}
                            <span className="sr-only">
                                {' '}
                                {met
                                    ? t('form.rule.met')
                                    : t('form.rule.notMet')}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

export function PasswordMatch({
    password,
    confirmation,
}: {
    password: string;
    confirmation: string;
}) {
    const { t } = useTranslation();

    if (confirmation.length === 0) {
        return null;
    }

    const matches = password === confirmation;

    return (
        <p
            aria-live="polite"
            className={`text-sm font-semibold ${matches ? 'text-ok' : 'text-danger'}`}
        >
            {matches ? t('form.passwordsMatch') : t('form.passwordsDontMatch')}
        </p>
    );
}
