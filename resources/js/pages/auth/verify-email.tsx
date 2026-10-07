import { router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    Button,
    PrimaryButton,
    StatusMessage,
} from '@/components/form-controls';
import { MailIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';
import AuthLayout, { TapedNote } from '@/layouts/auth-layout';

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
            heading={{
                lead: t('auth.verifyEmail.heroLead'),
                highlight: t('auth.verifyEmail.heroHighlight'),
            }}
            aside={
                <TapedNote icon={<MailIcon size={40} />}>
                    {t('auth.verifyEmail.note')}
                </TapedNote>
            }
        >
            {status === 'verification-link-sent' && (
                <StatusMessage
                    status={t('auth.verifyEmail.sent')}
                    className="-rotate-1"
                />
            )}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <PrimaryButton
                    type="submit"
                    disabled={processing}
                    className="max-w-full text-center whitespace-normal"
                >
                    {t('auth.verifyEmail.resend')}
                </PrimaryButton>
            </form>

            <form onSubmit={logout}>
                <Button type="submit" variant="ghost" className="px-0">
                    {t('auth.verifyEmail.logOut')}
                </Button>
            </form>
        </AuthLayout>
    );
}
