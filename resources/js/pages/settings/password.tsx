import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    InputError,
    Label,
    PrimaryButton,
    StatusMessage,
    TextInput,
} from '@/components/form-controls';
import AppLayout from '@/layouts/app-layout';

export default function UpdatePassword({ status }: { status?: string }) {
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
            <Head title="Change password" />
            <div className="flex justify-center p-6">
                <div className="w-full max-w-sm rounded-none border border-gray-200 bg-white p-8 shadow-sm">
                    <div className="mb-6">
                        <h1 className="text-xl font-semibold text-gray-900">
                            Change password
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Ensure your account is using a long, random password
                            to stay secure.
                        </p>
                    </div>

                    {status === 'password-updated' && (
                        <StatusMessage status="Your password has been updated." />
                    )}

                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="current_password">
                                Current password
                            </Label>
                            <TextInput
                                id="current_password"
                                type="password"
                                autoFocus
                                autoComplete="current-password"
                                value={data.current_password}
                                onChange={(e) =>
                                    setData('current_password', e.target.value)
                                }
                            />
                            <InputError message={errors.current_password} />
                        </div>

                        <div className="flex flex-col gap-1">
                            <Label htmlFor="password">New password</Label>
                            <TextInput
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                value={data.password}
                                onChange={(e) =>
                                    setData('password', e.target.value)
                                }
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex flex-col gap-1">
                            <Label htmlFor="password_confirmation">
                                Confirm new password
                            </Label>
                            <TextInput
                                id="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={data.password_confirmation}
                                onChange={(e) =>
                                    setData(
                                        'password_confirmation',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <PrimaryButton
                            type="submit"
                            disabled={processing}
                            className="mt-2"
                        >
                            Update password
                        </PrimaryButton>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
