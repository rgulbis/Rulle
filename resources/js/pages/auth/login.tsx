import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    AuthLink,
    Checkbox,
    InputError,
    Label,
    PasswordInput,
    PrimaryButton,
    StatusMessage,
    TextInput,
} from '@/components/form-controls';
import { ArrowRightIcon, QrIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';
import AuthLayout, { AuthFooter, TapedNote } from '@/layouts/auth-layout';

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
            heading={{
                lead: t('auth.login.heroLead'),
                highlight: t('auth.login.heroHighlight'),
                tail: t('auth.login.heroTail'),
            }}
            blurb={t('auth.login.blurb')}
            aside={
                <TapedNote
                    eyebrow={t('auth.login.noteEyebrow')}
                    icon={<QrIcon size={48} />}
                >
                    {t('auth.login.note')}
                </TapedNote>
            }
        >
            <StatusMessage status={status} />

            <form
                onSubmit={submit}
                className="short:lg:gap-3.5 flex flex-col gap-4 lg:gap-5"
            >
                <div className="flex flex-col gap-2">
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
                        placeholder={t('form.emailPlaceholder')}
                        value={data.email}
                        aria-invalid={!!errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="flex flex-col gap-2">
                    <div className="flex items-baseline justify-between gap-4">
                        <Label htmlFor="password">{t('auth.password')}</Label>
                        <AuthLink href="/forgot-password" className="text-sm">
                            {t('auth.login.forgotPassword')}
                        </AuthLink>
                    </div>
                    <PasswordInput
                        id="password"
                        autoComplete="current-password"
                        value={data.password}
                        aria-invalid={!!errors.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} />
                </div>

                <Checkbox
                    label={t('auth.login.rememberMe')}
                    checked={data.remember}
                    onChange={(e) => setData('remember', e.target.checked)}
                />

                <PrimaryButton type="submit" disabled={processing}>
                    {t('auth.login.submit')}
                    <ArrowRightIcon />
                </PrimaryButton>
            </form>

            <AuthFooter>
                <p>
                    {t('auth.login.noAccount')}{' '}
                    <AuthLink href="/register">
                        {t('auth.login.signUp')}
                    </AuthLink>
                </p>
                <p className="text-muted">
                    {t('auth.login.justLooking')}{' '}
                    <AuthLink href="/livestream">
                        {t('auth.login.watchLive')}
                    </AuthLink>
                </p>
            </AuthFooter>
        </AuthLayout>
    );
}
