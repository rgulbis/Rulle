import { router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { PrimaryButton, StatusMessage } from '@/components/form-controls';
import AuthLayout from '@/layouts/auth-layout';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    const logout: FormEventHandler = (e) => {
        e.preventDefault();
        router.post('/logout');
    };

    return (
        <AuthLayout
            title="Verify email"
            description="Thanks for signing up! Before getting started, please verify your email address by clicking the link we just emailed to you."
        >
            {status === 'verification-link-sent' && (
                <StatusMessage status="A new verification link has been sent to the email address you provided during registration." />
            )}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <PrimaryButton type="submit" disabled={processing}>
                    Resend verification email
                </PrimaryButton>
            </form>

            <form onSubmit={logout} className="mt-6">
                <button
                    type="submit"
                    className="text-sm font-medium text-red-600 hover:text-red-500"
                >
                    Log out
                </button>
            </form>
        </AuthLayout>
    );
}
