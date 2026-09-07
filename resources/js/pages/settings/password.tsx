import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { AuthLink, InputError, Label, PrimaryButton, StatusMessage, TextInput } from '@/components/form-controls';
import AuthLayout from '@/layouts/auth-layout';

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
            onError: () => reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Change password" description="Ensure your account is using a long, random password to stay secure.">
            {status === 'password-updated' && <StatusMessage status="Your password has been updated." />}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <Label htmlFor="current_password">Current password</Label>
                    <TextInput
                        id="current_password"
                        type="password"
                        autoFocus
                        autoComplete="current-password"
                        value={data.current_password}
                        onChange={(e) => setData('current_password', e.target.value)}
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
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="flex flex-col gap-1">
                    <Label htmlFor="password_confirmation">Confirm new password</Label>
                    <TextInput
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                    <InputError message={errors.password_confirmation} />
                </div>

                <PrimaryButton type="submit" disabled={processing} className="mt-2">
                    Update password
                </PrimaryButton>
            </form>

            <p className="mt-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                <AuthLink href="/dashboard">Back to dashboard</AuthLink>
            </p>
        </AuthLayout>
    );
}
