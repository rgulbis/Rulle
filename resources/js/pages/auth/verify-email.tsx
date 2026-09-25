import { router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { PrimaryButton, StatusMessage } from '@/components/form-controls';
import { useTranslation } from '@/lib/i18n/context';
import AuthLayout from '@/layouts/auth-layout';

export default function VerifyEmail({ status }: { status?: string }) {
    const { t } = useTranslation();
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
            title={t('auth.verifyEmail.title')}
            description={t('auth.verifyEmail.description')}
        >
            {status === 'verification-link-sent' && (
                <StatusMessage status={t('auth.verifyEmail.sent')} />
            )}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <PrimaryButton type="submit" disabled={processing}>
                    {t('auth.verifyEmail.resend')}
                </PrimaryButton>
            </form>

            <form onSubmit={logout} className="mt-6">
                <button
                    type="submit"
                    className="text-sm font-medium text-red-600 hover:text-red-500"
                >
                    {t('auth.verifyEmail.logOut')}
                </button>
            </form>
        </AuthLayout>
    );
}
