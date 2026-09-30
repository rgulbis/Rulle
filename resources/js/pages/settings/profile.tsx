import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Avatar } from '@/components/chat-thread';
import {
    InputError,
    Label,
    PageHeader,
    PrimaryButton,
    StatusMessage,
    TextInput,
} from '@/components/form-controls';
import SettingsTabs from '@/components/settings-tabs';
import AppLayout, { PageContainer } from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';
import type { Auth } from '@/types/auth';

export default function Profile({ status }: { status?: string }) {
    const { t } = useTranslation();
    const { auth } = usePage<{ auth: Auth }>().props;
    const { data, setData, patch, processing, errors, isDirty } = useForm({
        name: auth.user.name,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch('/settings/profile', { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={t('settings.profile.title')} />
            <PageContainer className="max-w-2xl">
                <PageHeader
                    title={t('settings.profile.title')}
                    description={t('settings.profile.subtitle')}
                />
                <SettingsTabs />

                <div className="border-ink bg-paper shadow-hard-lg border-2 p-6 lg:p-8">
                    {status === 'profile-updated' && (
                        <StatusMessage status={t('settings.profile.updated')} />
                    )}

                    <form onSubmit={submit} className="flex flex-col gap-5">
                        {/* Live preview of how the name shows up in chat. */}
                        <div className="border-line flex items-center gap-3 border-2 border-dashed px-4 py-3">
                            <Avatar
                                user={{
                                    id: auth.user.id,
                                    name: data.name || auth.user.name,
                                    role: auth.user.role,
                                }}
                            />
                            <div className="min-w-0">
                                <p className="truncate font-semibold">
                                    {data.name || auth.user.name}
                                </p>
                                <p className="text-muted text-sm">
                                    {t('settings.profile.preview')}
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="name">
                                {t('auth.register.name')}
                            </Label>
                            <TextInput
                                id="name"
                                autoComplete="name"
                                maxLength={255}
                                value={data.name}
                                aria-invalid={!!errors.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="email">{t('auth.email')}</Label>
                            <TextInput
                                id="email"
                                value={auth.user.email}
                                readOnly
                                aria-describedby="email-hint"
                                className="text-muted"
                            />
                            <p id="email-hint" className="text-muted text-sm">
                                {t('settings.profile.emailHint')}
                            </p>
                        </div>

                        <PrimaryButton
                            type="submit"
                            disabled={processing || !isDirty}
                            className="mt-2 self-start"
                        >
                            {t('settings.profile.submit')}
                        </PrimaryButton>
                    </form>
                </div>
            </PageContainer>
        </AppLayout>
    );
}
