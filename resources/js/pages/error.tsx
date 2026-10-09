import { Head, Link } from '@inertiajs/react';
import { Button, Eyebrow, LinkButton } from '@/components/form-controls';
import { useTranslation } from '@/lib/i18n/context';
import type { TranslationKey } from '@/lib/i18n/en';

const KNOWN = [403, 404, 419, 429, 500, 503] as const;

// Deliberately doesn't use AppLayout: a 404 for an unmatched URL never goes
// through the web middleware, so the shared `auth` / `park` props that layout
// reads aren't there - and an error page that itself crashes is worse than
// a plain one.
export default function ErrorPage({ status }: { status: number }) {
    const { t } = useTranslation();
    const code = (KNOWN as readonly number[]).includes(status) ? status : 500;

    return (
        <>
            <Head title={t(`error.${code}.heading` as TranslationKey)} />
            <div className="bg-ground text-ink flex min-h-dvh flex-col">
                <header className="border-ink border-b-2">
                    <div className="mx-auto flex max-w-7xl items-center px-5 py-4 lg:px-10">
                        <Link
                            href="/"
                            className="font-display text-3xl font-black uppercase"
                        >
                            {t('nav.brand')}
                        </Link>
                    </div>
                </header>

                <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col justify-center gap-5 px-5 py-14">
                    <Eyebrow className="text-muted">{status}</Eyebrow>
                    <h1 className="font-display text-5xl leading-none font-black uppercase sm:text-7xl">
                        <span className="bg-accent text-accent-ink shadow-hard inline-block -rotate-[2deg] px-3">
                            {t(`error.${code}.heading` as TranslationKey)}
                        </span>
                    </h1>
                    <p className="text-muted max-w-md text-lg">
                        {t(`error.${code}.body` as TranslationKey)}
                    </p>
                    <div className="flex flex-wrap gap-3 pt-2">
                        <LinkButton href="/">{t('error.home')}</LinkButton>
                        {code !== 403 && code !== 404 && (
                            <Button
                                variant="secondary"
                                onClick={() => window.location.reload()}
                            >
                                {t('error.retry')}
                            </Button>
                        )}
                    </div>
                </main>
            </div>
        </>
    );
}
