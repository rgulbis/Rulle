import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import { Button, Eyebrow, LinkButton } from '@/components/form-controls';
import { CheckIcon } from '@/components/icons';
import QrCode from '@/components/qr-code';
import AppLayout, { PageContainer } from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';
import { useQrToken, type QrTokenData } from '@/lib/use-qr-token';
import type { Auth } from '@/types/auth';

type Pass =
    | {
          kind: 'subscription';
          name: string | null;
          name_lv: string | null;
          billing_interval: 'month' | 'year' | null;
          canceled: boolean;
          ends_at: string | null;
          renews_at: string | null;
      }
    | {
          kind: 'purchase';
          name: string;
          name_lv: string | null;
          unlimited_entries: boolean;
          visits_remaining: number | null;
      };

type NextReservation = {
    id: number;
    starts_at: string;
    ends_at: string;
    group_size: number;
};

// How long the code stays hidden after a successful scan before the next one
// is shown (see useQrToken's rotate()).
const SCAN_COOLDOWN_MS = 2500;

type Props = {
    status?: string;
    pass: Pass | null;
    nextReservation: NextReservation | null;
    qrToken: QrTokenData | null;
};

export default function Dashboard({
    status,
    pass,
    nextReservation,
    qrToken,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { t } = useTranslation();
    const { post, processing } = useForm({});
    const [checkedIn, setCheckedIn] = useState(auth.user.checked_in);
    // Bumped on every scan, so the QR code knows its code was just spent.
    const [scans, setScans] = useState(0);

    useEffect(() => {
        setCheckedIn(auth.user.checked_in);
    }, [auth.user.checked_in]);

    useEffect(() => {
        const channel = window.Echo.private(`App.Models.User.${auth.user.id}`);

        channel.listen('.check-in.updated', (e: { checked_in: boolean }) => {
            setCheckedIn(e.checked_in);
            setScans((n) => n + 1);

            // A scan can also spend a visit (or the last one), so the pass
            // card is re-read from the server. Only those two props: the
            // QR token isn't re-issued by this.
            router.reload({ only: ['pass', 'nextReservation'] });
        });

        return () => {
            window.Echo.leave(`App.Models.User.${auth.user.id}`);
        };
    }, [auth.user.id]);

    const resendVerification: FormEventHandler = (e) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    return (
        <AppLayout>
            <Head title={t('nav.dashboard')} />
            <PageContainer>
                <h1 className="font-display mb-10 text-6xl leading-none font-black uppercase lg:text-8xl">
                    {t('dashboard.welcome', { name: auth.user.name })}
                </h1>

                {!auth.user.email_verified_at && (
                    <div className="border-ink bg-accent text-accent-ink shadow-hard mb-10 flex flex-col gap-4 border-2 p-6">
                        {status === 'verification-link-sent' ? (
                            <p className="font-semibold">
                                {t('dashboard.verificationSent')}
                            </p>
                        ) : (
                            <p className="font-semibold">
                                {t('dashboard.pleaseVerify')}
                            </p>
                        )}
                        <form onSubmit={resendVerification}>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="max-w-full border-[#16161a] bg-[#16161a] text-center whitespace-normal text-[#f7f6f2] shadow-none"
                            >
                                {t('dashboard.resendVerification')}
                            </Button>
                        </form>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-12">
                    <EntryPass
                        verified={!!auth.user.email_verified_at}
                        qrToken={qrToken}
                        checkedIn={checkedIn}
                        scans={scans}
                    />

                    <div className="grid content-start gap-6 sm:grid-cols-2 lg:col-span-7">
                        <PassCard pass={pass} />
                        <NextBookingCard reservation={nextReservation} />
                    </div>
                </div>
            </PageContainer>
        </AppLayout>
    );
}

function EntryPass({
    verified,
    qrToken,
    checkedIn,
    scans,
}: {
    verified: boolean;
    qrToken: QrTokenData | null;
    checkedIn: boolean;
    scans: number;
}) {
    const { t } = useTranslation();

    return (
        <section className="border-ink flex flex-col border-2 bg-[#16161a] text-[#f7f6f2] lg:col-span-5">
            <div className="flex items-center justify-between gap-4 px-7 py-5">
                <h2 className="font-mono text-sm font-semibold tracking-[0.12em] uppercase">
                    {t('dashboard.entryPass')}
                </h2>
                {verified &&
                    (checkedIn ? (
                        <span className="bg-accent text-accent-ink flex items-center gap-2 px-3 py-1.5 text-sm font-semibold">
                            <span className="size-2 rounded-full bg-[#16161a]" />
                            {t('dashboard.checkedIn')}
                        </span>
                    ) : (
                        <span className="flex items-center gap-2 border border-[#6e6d68] px-3 py-1.5 text-sm text-[#d9d7d0]">
                            <span className="size-2 rounded-full border-[1.5px] border-[#d9d7d0]" />
                            {t('dashboard.notCheckedIn')}
                        </span>
                    ))}
            </div>
            {verified ? (
                <>
                    <RotatingQrCode initial={qrToken} scans={scans} />
                    <div className="mt-auto flex flex-col gap-1 border-t border-[#3a3a3f] px-7 py-5">
                        <p className="text-lg font-semibold">
                            {t('dashboard.showAtDesk')}
                        </p>
                        <p className="text-base text-[#b9b8b2]">
                            {t('dashboard.brightnessHint')}
                        </p>
                    </div>
                </>
            ) : (
                <p className="px-7 pb-8 text-base text-[#b9b8b2]">
                    {t('dashboard.verifyForQrCode')}
                </p>
            )}
        </section>
    );
}

// Only mounted for a verified account, so the first token is already there
// and the hook never has to fetch one cold on a page that has none.
function RotatingQrCode({
    initial,
    scans,
}: {
    initial: QrTokenData | null;
    scans: number;
}) {
    const { t } = useTranslation();
    const { token, fresh, rotate } = useQrToken(initial);

    // A scan just spent this code: swap it for a new one, but only after a
    // pause long enough for staff to take the phone away from the camera.
    useEffect(() => {
        if (scans > 0) {
            rotate(SCAN_COOLDOWN_MS);
        }
    }, [scans, rotate]);

    return (
        <div className="flex justify-center px-5 pb-6 sm:px-7">
            <div className="relative max-w-full bg-white p-3">
                {/* Not drawn at all while it is retired or expired: a
                    faded code could still be read by a camera. */}
                {token && fresh && <QrCode value={token} size={280} />}
                {token && !fresh && (
                    <div
                        aria-hidden="true"
                        style={{ width: 280, height: 280 }}
                    />
                )}
                {!fresh && (
                    <p
                        role="status"
                        className="absolute inset-0 flex items-center justify-center p-6 text-center font-semibold text-[#16161a]"
                    >
                        {t('dashboard.qrRefreshing')}
                    </p>
                )}
            </div>
        </div>
    );
}

function PassCard({ pass }: { pass: Pass | null }) {
    const { t, tCount, locale, intlLocale } = useTranslation();

    const name = pass
        ? (locale === 'lv' && pass.name_lv) ||
          pass.name ||
          t('dashboard.subscription')
        : null;

    let detail: string | null = null;

    if (pass?.kind === 'subscription') {
        if (pass.canceled && pass.ends_at) {
            detail = t('dashboard.accessEnds', {
                date: new Date(pass.ends_at).toLocaleDateString(intlLocale),
            });
        } else if (pass.renews_at) {
            detail = t('dashboard.renewsOn', {
                date: new Date(pass.renews_at).toLocaleDateString(intlLocale),
            });
        } else {
            detail = t('dashboard.renewsAutomatically');
        }
    } else if (pass?.kind === 'purchase') {
        detail = pass.unlimited_entries
            ? t('subscriptions.unlimitedToday')
            : tCount(
                  'subscriptions.visitsRemaining',
                  pass.visits_remaining ?? 0,
              );
    }

    return (
        <section className="border-ink bg-paper flex flex-col gap-4 border-2 p-7">
            <Eyebrow className="text-muted">{t('dashboard.yourPass')}</Eyebrow>
            {pass ? (
                <>
                    <p className="font-display text-5xl leading-none font-black uppercase">
                        {name}
                    </p>
                    <p className="text-muted flex-1 text-base">{detail}</p>
                    <Link
                        href="/subscriptions"
                        className="self-start font-semibold underline decoration-2 underline-offset-4"
                    >
                        {t('dashboard.manage')}
                    </Link>
                </>
            ) : (
                <>
                    <p className="flex-1 text-lg">{t('dashboard.noPass')}</p>
                    <LinkButton
                        href="/subscriptions"
                        variant="accent"
                        className="self-start"
                    >
                        {t('dashboard.getPass')}
                    </LinkButton>
                </>
            )}
        </section>
    );
}

function NextBookingCard({
    reservation,
}: {
    reservation: NextReservation | null;
}) {
    const { t, intlLocale } = useTranslation();

    if (!reservation) {
        return (
            <section className="border-ink bg-paper flex flex-col gap-4 border-2 p-7">
                <Eyebrow className="text-muted">
                    {t('dashboard.nextBooking')}
                </Eyebrow>
                <p className="flex-1 text-lg">{t('dashboard.noBooking')}</p>
                <LinkButton
                    href="/reservations"
                    variant="secondary"
                    className="self-start"
                >
                    {t('dashboard.bookSlot')}
                </LinkButton>
            </section>
        );
    }

    const start = new Date(reservation.starts_at);
    const end = new Date(reservation.ends_at);
    const time = (d: Date) =>
        d.toLocaleTimeString(intlLocale, {
            hour: '2-digit',
            minute: '2-digit',
        });

    return (
        <section className="border-ink bg-paper flex flex-col gap-4 border-2 p-7">
            <Eyebrow className="text-muted">
                {t('dashboard.nextBooking')}
            </Eyebrow>
            <p className="font-display text-5xl leading-none font-black uppercase">
                {start.toLocaleDateString(intlLocale, {
                    weekday: 'short',
                    day: 'numeric',
                    month: 'short',
                })}
            </p>
            <div className="flex flex-1 flex-col gap-1 text-base">
                <p>
                    {time(start)}–{time(end)} ·{' '}
                    {t('reservations.peopleCount', {
                        count: reservation.group_size,
                    })}
                </p>
                <p className="text-ok flex items-center gap-2 font-semibold">
                    <CheckIcon size={18} />
                    {t('dashboard.paidConfirmed')}
                </p>
            </div>
            <div className="flex flex-wrap gap-5 font-semibold">
                <Link
                    href="/reservations"
                    className="underline decoration-2 underline-offset-4"
                >
                    {t('dashboard.viewBookings')}
                </Link>
                <Link
                    href={`/reservations/${reservation.id}/chat`}
                    className="underline decoration-2 underline-offset-4"
                >
                    {t('reservations.groupChat')}
                </Link>
            </div>
        </section>
    );
}
