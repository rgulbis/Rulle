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
import { useTranslation } from '@/lib/i18n/context';
import AuthLayout from '@/layouts/auth-layout';

export default function Login({ status }: { status?: string }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout
            title={t('auth.login.title')}
            description={t('auth.login.description')}
        >
            <StatusMessage status={status} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <Label htmlFor="email">{t('auth.email')}</Label>
                    <TextInput
                        id="email"
                        // Not type="email": the browser's own built-in
                        // validation for that type rejects a Unicode domain
                        // (e.g. admin@rullē.lv) before the form can even
                        // submit, which defeats the server-side IDN
                        // normalization that's supposed to accept it.
                        type="text"
                        inputMode="email"
                        autoFocus
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="flex flex-col gap-1">
                    <div className="flex items-center justify-between">
                        <Label htmlFor="password">{t('auth.password')}</Label>
                        <AuthLink href="/forgot-password">
                            {t('auth.login.forgotPassword')}
                        </AuthLink>
                    </div>
                    <TextInput
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} />
                </div>

                <label className="flex items-center gap-2 text-sm text-gray-500">
                    <input
                        type="checkbox"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                    />
                    {t('auth.login.rememberMe')}
                </label>

                <PrimaryButton
                    type="submit"
                    disabled={processing}
                    className="mt-2"
                >
                    {t('auth.login.submit')}
                </PrimaryButton>
            </form>

            <p className="mt-6 text-sm text-gray-500">
                {t('auth.login.noAccount')}{' '}
                <AuthLink href="/register">{t('auth.login.signUp')}</AuthLink>
            </p>
        </AuthLayout>
    );
}
