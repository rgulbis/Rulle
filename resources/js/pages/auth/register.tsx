import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    AuthLink,
    InputError,
    Label,
    PrimaryButton,
    TextInput,
} from '@/components/form-controls';
import { useTranslation } from '@/lib/i18n/context';
import AuthLayout from '@/layouts/auth-layout';

export default function Register() {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/register', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title={t('auth.register.title')}
            description={t('auth.register.description')}
        >
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <Label htmlFor="name">{t('auth.register.name')}</Label>
                    <TextInput
                        id="name"
                        autoFocus
                        autoComplete="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="flex flex-col gap-1">
                    <Label htmlFor="email">{t('auth.email')}</Label>
                    <TextInput
                        id="email"
                        type="email"
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="flex flex-col gap-1">
                    <Label htmlFor="password">{t('auth.password')}</Label>
                    <TextInput
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="flex flex-col gap-1">
                    <Label htmlFor="password_confirmation">
                        {t('auth.register.confirmPassword')}
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
                    {t('auth.register.submit')}
                </PrimaryButton>
            </form>

            <p className="mt-6 text-sm text-gray-500">
                {t('auth.register.haveAccount')}{' '}
                <AuthLink href="/login">{t('auth.register.logIn')}</AuthLink>
            </p>
        </AuthLayout>
    );
}
