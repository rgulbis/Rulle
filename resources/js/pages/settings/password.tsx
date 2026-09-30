import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    InputError,
    Label,
    PageHeader,
    PasswordInput,
    PrimaryButton,
    StatusMessage,
} from '@/components/form-controls';
import PasswordChecklist, {
    PasswordMatch,
} from '@/components/password-checklist';
import SettingsTabs from '@/components/settings-tabs';
import AppLayout, { PageContainer } from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';

export default function UpdatePassword({ status }: { status?: string }) {
    const { t } = useTranslation();
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/settings/password', {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: () =>
                reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <AppLayout>
            <Head title={t('settings.password.title')} />
            <PageContainer className="max-w-2xl">
                <PageHeader
                    title={t('settings.password.title')}
                    description={t('settings.password.subtitle')}
                />
                <SettingsTabs />
                <div className="border-ink bg-paper shadow-hard-lg border-2 p-6 lg:p-8">
                    {status === 'password-updated' && (
                        <StatusMessage
                            status={t('settings.password.updated')}
                        />
                    )}

                    <form onSubmit={submit} className="flex flex-col gap-5">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="current_password">
                                {t('settings.password.current')}
                            </Label>
                            <PasswordInput
                                id="current_password"
                                autoFocus
                                autoComplete="current-password"
                                value={data.current_password}
                                onChange={(e) =>
                                    setData('current_password', e.target.value)
                                }
                            />
                            <InputError message={errors.current_password} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="password">
                                {t('settings.password.new')}
                            </Label>
                            <PasswordInput
                                id="password"
                                autoComplete="new-password"
                                aria-describedby="password-rules"
                                value={data.password}
                                onChange={(e) =>
                                    setData('password', e.target.value)
                                }
                            />
                            <PasswordChecklist
                                id="password-rules"
                                password={data.password}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="password_confirmation">
                                {t('settings.password.confirm')}
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                autoComplete="new-password"
                                value={data.password_confirmation}
                                onChange={(e) =>
                                    setData(
                                        'password_confirmation',
                                        e.target.value,
                                    )
                                }
                            />
                            <PasswordMatch
                                password={data.password}
                                confirmation={data.password_confirmation}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <PrimaryButton
                            type="submit"
                            disabled={processing}
                            className="mt-2 self-start"
                        >
                            {t('settings.password.submit')}
                        </PrimaryButton>
                    </form>
                </div>
            </PageContainer>
        </AppLayout>
    );
}
