import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { AuthLink, PrimaryButton, StatusMessage } from '@/components/form-controls';
import QrCode from '@/components/qr-code';
import type { Auth } from '@/types/auth';

export default function Dashboard({ status }: { status?: string }) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { post, processing } = useForm({});

    const resendVerification: FormEventHandler = (e) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex min-h-screen items-center justify-center bg-[#FDFDFC] p-6 text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <div className="w-full max-w-sm rounded-lg bg-white p-8 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]">
                    <h1 className="mb-1 text-lg font-medium">Welcome, {auth.user.name}.</h1>
                    <p className="mb-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">You're logged in.</p>

                    {!auth.user.email_verified_at && (
                        <div className="mb-6 rounded-md border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]">
                            {status === 'verification-link-sent' ? (
                                <StatusMessage status="A new verification link has been sent to your email address." />
                            ) : (
                                <p className="mb-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">Please verify your email address.</p>
                            )}
                            <form onSubmit={resendVerification}>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="text-sm font-medium text-[#f53003] underline underline-offset-4 disabled:opacity-50 dark:text-[#FF4433]"
                                >
                                    Resend verification email
                                </button>
                            </form>
                        </div>
                    )}

                    <div className="mb-6 flex flex-col items-center gap-3 rounded-md border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]">
                        <QrCode value={auth.user.qr_code} />
                        <p
                            className={
                                'text-sm font-medium ' +
                                (auth.user.checked_in ? 'text-green-600 dark:text-green-500' : 'text-[#706f6c] dark:text-[#A1A09A]')
                            }
                        >
                            {auth.user.checked_in ? 'Checked in' : 'Not checked in'}
                        </p>
                    </div>

                    {auth.user.role === 'staff' && (
                        <p className="mb-6 text-sm">
                            <AuthLink href="/staff/scan">Open scanner</AuthLink>
                        </p>
                    )}

                    <div className="flex items-center justify-between">
                        <AuthLink href="/settings/password">Change password</AuthLink>
                        <PrimaryButton onClick={() => router.post('/logout')}>Log out</PrimaryButton>
                    </div>
                </div>
            </div>
        </>
    );
}
