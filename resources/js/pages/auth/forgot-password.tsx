import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    AuthLink,
    InputError,
    Label,
    PrimaryButton,
    StatusMessage,
    TextInput,
} from '@/components/form-controls';
import { MailIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';
import AuthLayout, { AuthFooter, TapedNote } from '@/layouts/auth-layout';

export default function ForgotPassword({ status }: { status?: string }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/forgot-password');
    };

    return (
        <AuthLayout
            title={t('auth.forgotPassword.title')}
            description={t('auth.forgotPassword.description')}
            heading={{
                lead: t('auth.forgotPassword.heroLead'),
                highlight: t('auth.forgotPassword.heroHighlight'),
            }}
            blurb={t('auth.forgotPassword.blurb')}
            aside={
                <TapedNote icon={<MailIcon size={40} />}>
                    {t('auth.forgotPassword.note')}
                </TapedNote>
            }
        >
            {/* Already in the visitor's language — the server translates it. */}
            <StatusMessage status={status} className="-rotate-1" />

            <form
                onSubmit={submit}
                className="short:lg:gap-3.5 flex flex-col gap-4 lg:gap-5"
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="email">{t('auth.email')}</Label>
                    <TextInput
                        id="email"
                        // See login.tsx: not type="email" so a Unicode
                        // domain isn't rejected by the browser itself
                        // before the server-side IDN normalization runs.
                        type="text"
                        inputMode="email"
                        autoFocus
                        autoComplete="username"
                        placeholder={t('form.emailPlaceholder')}
                        value={data.email}
                        aria-invalid={!!errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('auth.forgotPassword.submit')}
                </PrimaryButton>
            </form>

            <AuthFooter>
                <p>
                    {t('auth.forgotPassword.remembered')}{' '}
                    <AuthLink href="/login">
                        {t('auth.forgotPassword.logIn')}
                    </AuthLink>
                </p>
            </AuthFooter>
        </AuthLayout>
    );
}
