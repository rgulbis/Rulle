import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    AuthLink,
    InputError,
    Label,
    PasswordInput,
    PrimaryButton,
    TextInput,
} from '@/components/form-controls';
import { ArrowRightIcon } from '@/components/icons';
import PasswordChecklist, {
    PasswordMatch,
} from '@/components/password-checklist';
import { useTranslation } from '@/lib/i18n/context';
import AuthLayout, { AuthFooter } from '@/layouts/auth-layout';

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

    const perks = [
        t('auth.register.perkQr'),
        t('auth.register.perkBook'),
        t('auth.register.perkChat'),
        t('auth.register.perkLive'),
    ];
    const tilts = ['-rotate-2', 'rotate-[1.5deg]', 'rotate-1', '-rotate-1'];

    return (
        <AuthLayout
            title={t('auth.register.title')}
            description={t('auth.register.description')}
            heading={{
                lead: t('auth.register.heroLead'),
                highlight: t('auth.register.heroHighlight'),
            }}
            blurb={t('auth.register.blurb')}
            aside={
                <ul className="grid grid-cols-2 gap-4">
                    {perks.map((perk, i) => (
                        <li
                            key={perk}
                            className={`flex flex-col gap-1 p-5 transition hover:rotate-0 ${tilts[i]} ${
                                i === 1
                                    ? 'bg-accent text-accent-ink'
                                    : i === 2
                                      ? 'border-2 border-[#f7f6f2]'
                                      : 'bg-[#f7f6f2] text-[#16161a]'
                            }`}
                        >
                            <span className="font-mono text-xs font-semibold">
                                0{i + 1}
                            </span>
                            <span className="font-display text-2xl leading-none font-black uppercase">
                                {perk}
                            </span>
                        </li>
                    ))}
                </ul>
            }
        >
            <form
                onSubmit={submit}
                className="short:lg:gap-3.5 flex flex-col gap-4 lg:gap-5"
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="name">{t('auth.register.name')}</Label>
                    <TextInput
                        id="name"
                        autoFocus
                        autoComplete="name"
                        value={data.name}
                        aria-invalid={!!errors.name}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="email">{t('auth.email')}</Label>
                    <TextInput
                        id="email"
                        type="email"
                        autoComplete="username"
                        placeholder={t('form.emailPlaceholder')}
                        value={data.email}
                        aria-invalid={!!errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="password">{t('auth.password')}</Label>
                    <PasswordInput
                        id="password"
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
                        {t('auth.register.confirmPassword')}
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
                    {t('auth.register.submit')}
                    <ArrowRightIcon />
                </PrimaryButton>
            </form>

            <AuthFooter>
                <p>
                    {t('auth.register.haveAccount')}{' '}
                    <AuthLink href="/login">
                        {t('auth.register.logIn')}
                    </AuthLink>
                </p>
            </AuthFooter>
        </AuthLayout>
    );
}
