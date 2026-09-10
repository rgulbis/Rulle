import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { StatusMessage } from '@/components/form-controls';
import QrCode from '@/components/qr-code';
import AppLayout from '@/layouts/app-layout';
import type { Auth } from '@/types/auth';

export default function Dashboard({ status }: { status?: string }) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { post, processing } = useForm({});

    const resendVerification: FormEventHandler = (e) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    return (
        <AppLayout>
            <Head title="Dashboard" />
            <div className="flex justify-center p-6">
                <div className="w-full max-w-sm rounded-none border border-gray-200 bg-white p-8 shadow-sm">
                    <h1 className="mb-1 text-xl font-semibold text-gray-900">
                        Welcome, {auth.user.name}.
                    </h1>
                    <p className="mb-6 text-sm text-gray-500">
                        You're logged in.
                    </p>

                    {!auth.user.email_verified_at && (
                        <div className="mb-6 rounded-none border border-amber-200 bg-amber-50 p-4">
                            {status === 'verification-link-sent' ? (
                                <StatusMessage status="A new verification link has been sent to your email address." />
                            ) : (
                                <p className="mb-3 text-sm text-amber-800">
                                    Please verify your email address to
                                    subscribe or make purchases.
                                </p>
                            )}
                            <form onSubmit={resendVerification}>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="text-sm font-semibold text-yellow-700 hover:text-yellow-600 disabled:opacity-50"
                                >
                                    Resend verification email
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
                                    (auth.user.checked_in
                                        ? 'text-green-600'
                                        : 'text-gray-500')
                                }
                            >
                                {auth.user.checked_in
                                    ? 'Checked in'
                                    : 'Not checked in'}
                            </p>
                        </div>
                    ) : (
                        <div className="rounded-none border border-gray-200 p-4 text-center text-sm text-gray-500">
                            Verify your email to get your entry QR code.
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
