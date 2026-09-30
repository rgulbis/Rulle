import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    InputError,
    Label,
    PasswordInput,
    PrimaryButton,
    TextInput,
} from '@/components/form-controls';
import PasswordChecklist, {
    PasswordMatch,
} from '@/components/password-checklist';
import { useTranslation } from '@/lib/i18n/context';
import AuthLayout from '@/layouts/auth-layout';

export default function ResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/reset-password', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title={t('auth.resetPassword.title')}
            description={t('auth.resetPassword.description')}
            heading={{
                lead: t('auth.resetPassword.heroLead'),
                highlight: t('auth.resetPassword.heroHighlight'),
            }}
            blurb={t('auth.resetPassword.blurb')}
        >
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
                        autoComplete="username"
                        value={data.email}
                        aria-invalid={!!errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="password">
                        {t('settings.password.new')}
                    </Label>
                    <PasswordInput
                        id="password"
                        autoFocus
                        autoComplete="new-password"
                        aria-describedby="password-rules"
                        value={data.password}
                        aria-invalid={!!errors.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <PasswordChecklist
                        id="password-rules"
                        password={data.password}
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="password_confirmation">
                        {t('auth.resetPassword.confirmPassword')}
                    </Label>
                    <PasswordInput
                        id="password_confirmation"
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        aria-invalid={!!errors.password_confirmation}
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                    />
                    <PasswordMatch
                        password={data.password}
                        confirmation={data.password_confirmation}
                    />
                    <InputError message={errors.password_confirmation} />
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('auth.resetPassword.submit')}
                </PrimaryButton>
            </form>
        </AuthLayout>
    );
}
