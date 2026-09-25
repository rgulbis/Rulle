import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    InputError,
    Label,
    PrimaryButton,
    TextInput,
} from '@/components/form-controls';
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
        >
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
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
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="flex flex-col gap-1">
                    <Label htmlFor="password">
                        {t('settings.password.new')}
                    </Label>
                    <TextInput
                        id="password"
                        type="password"
                        autoFocus
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="flex flex-col gap-1">
                    <Label htmlFor="password_confirmation">
                        {t('auth.resetPassword.confirmPassword')}
                    </Label>
                    <TextInput
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                    />
                    <InputError message={errors.password_confirmation} />
                </div>

                <PrimaryButton
                    type="submit"
                    disabled={processing}
                    className="mt-2"
                >
                    {t('auth.resetPassword.submit')}
                </PrimaryButton>
            </form>
        </AuthLayout>
    );
}
