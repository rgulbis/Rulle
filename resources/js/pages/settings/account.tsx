import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    Button,
    buttonClasses,
    InputError,
    Label,
    PageHeader,
    PasswordInput,
    StatusMessage,
} from '@/components/form-controls';
import SettingsTabs from '@/components/settings-tabs';
import AppLayout, { PageContainer } from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';

export default function Account({
    canDelete,
    deleteBlocked,
}: {
    canDelete: boolean;
    deleteBlocked: boolean;
}) {
    const { t } = useTranslation();
    const {
        data,
        setData,
        delete: destroy,
        processing,
        errors,
    } = useForm({
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (!window.confirm(t('settings.account.deleteConfirm'))) {
            return;
        }

        destroy('/settings/account', { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={t('settings.account.title')} />
            <PageContainer className="max-w-2xl">
                <PageHeader
                    title={t('settings.account.title')}
                    description={t('settings.account.subtitle')}
                />
                <SettingsTabs />

                <div className="flex flex-col gap-6">
                    <section className="border-ink bg-paper shadow-hard-lg flex flex-col gap-4 border-2 p-6 lg:p-8">
                        <h2 className="font-display text-2xl font-black uppercase">
                            {t('settings.account.exportHeading')}
                        </h2>
                        <p className="text-muted">
                            {t('settings.account.exportBody')}
                        </p>
                        {/* A plain link: the response is a file, not an Inertia page. */}
                        <a
                            href="/settings/account/export"
                            download
                            className={`${buttonClasses('secondary')} self-start`}
                        >
                            {t('settings.account.exportButton')}
                        </a>
                    </section>

                    <section className="border-danger bg-paper flex flex-col gap-4 border-2 p-6 lg:p-8">
                        <h2 className="font-display text-danger text-2xl font-black uppercase">
                            {t('settings.account.deleteHeading')}
                        </h2>
                        {!canDelete ? (
                            <p className="text-muted">
                                {t('settings.account.deleteStaff')}
                            </p>
                        ) : (
                            <>
                                <p className="text-muted">
                                    {t('settings.account.deleteBody')}
                                </p>
                                {deleteBlocked ? (
                                    <StatusMessage
                                        tone="warning"
                                        status={t(
                                            'settings.account.deleteBlocked',
                                        )}
                                    />
                                ) : (
                                    <form
                                        onSubmit={submit}
                                        className="flex flex-col gap-4"
                                    >
                                        <div className="flex flex-col gap-2">
                                            <Label htmlFor="delete_password">
                                                {t(
                                                    'settings.account.passwordLabel',
                                                )}
                                            </Label>
                                            <PasswordInput
                                                id="delete_password"
                                                autoComplete="current-password"
                                                value={data.password}
                                                onChange={(e) =>
                                                    setData(
                                                        'password',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            variant="danger"
                                            disabled={
                                                processing || !data.password
                                            }
                                            className="self-start"
                                        >
                                            {t('settings.account.deleteButton')}
                                        </Button>
                                    </form>
                                )}
                            </>
                        )}
                    </section>
                </div>
            </PageContainer>
        </AppLayout>
    );
}
