import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { AuthLink, InputError, Label, PrimaryButton, StatusMessage, TextInput } from '@/components/form-controls';
import AuthLayout from '@/layouts/auth-layout';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/forgot-password');
    };

    return (
        <AuthLayout title="Forgot password" description="Enter your email and we'll send you a link to reset your password.">
            <StatusMessage status={status} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <Label htmlFor="email">Email</Label>
                    <TextInput
                        id="email"
                        type="email"
                        autoFocus
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>

                <PrimaryButton type="submit" disabled={processing} className="mt-2">
                    Email password reset link
                </PrimaryButton>
            </form>

            <p className="mt-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Remembered your password? <AuthLink href="/login">Log in</AuthLink>
            </p>
        </AuthLayout>
    );
}
