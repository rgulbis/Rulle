import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import { StatusMessage } from '@/components/form-controls';
import QrCode from '@/components/qr-code';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';
import type { Auth } from '@/types/auth';

export default function Dashboard({ status }: { status?: string }) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { t } = useTranslation();
    const { post, processing } = useForm({});
    const [checkedIn, setCheckedIn] = useState(auth.user.checked_in);

    useEffect(() => {
        setCheckedIn(auth.user.checked_in);
    }, [auth.user.checked_in]);

    useEffect(() => {
        const channel = window.Echo.private(`App.Models.User.${auth.user.id}`);

        channel.listen('.check-in.updated', (e: { checked_in: boolean }) => {
            setCheckedIn(e.checked_in);
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
            <div className="flex justify-center p-6">
                <div className="w-full max-w-sm rounded-none border border-gray-200 bg-white p-8 shadow-sm">
                    <h1 className="mb-1 text-xl font-semibold text-gray-900">
                        {t('dashboard.welcome', { name: auth.user.name })}
                    </h1>
                    <p className="mb-6 text-sm text-gray-500">
                        {t('dashboard.loggedIn')}
                    </p>

                    {!auth.user.email_verified_at && (
                        <div className="mb-6 rounded-none border border-amber-200 bg-amber-50 p-4">
                            {status === 'verification-link-sent' ? (
                                <StatusMessage
                                    status={t('dashboard.verificationSent')}
                                />
                            ) : (
                                <p className="mb-3 text-sm text-amber-800">
                                    {t('dashboard.pleaseVerify')}
                                </p>
                            )}
                            <form onSubmit={resendVerification}>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="text-sm font-semibold text-yellow-700 hover:text-yellow-600 disabled:opacity-50"
                                >
                                    {t('dashboard.resendVerification')}
                                </button>
                            </form>
                        </div>
                    )}

                    {auth.user.email_verified_at ? (
                        <div className="flex flex-col items-center gap-3 rounded-none border border-gray-200 p-4">
                            <QrCode value={auth.user.qr_code} />
                            <p
                                className={
                                    'text-sm font-medium ' +
                                    (checkedIn
                                        ? 'text-green-600'
                                        : 'text-gray-500')
                                }
                            >
                                {checkedIn
                                    ? t('dashboard.checkedIn')
                                    : t('dashboard.notCheckedIn')}
                            </p>
                        </div>
                    ) : (
                        <div className="rounded-none border border-gray-200 p-4 text-center text-sm text-gray-500">
                            {t('dashboard.verifyForQrCode')}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
