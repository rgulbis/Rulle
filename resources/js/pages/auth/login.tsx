import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { AuthLink, InputError, Label, PrimaryButton, StatusMessage, TextInput } from '@/components/form-controls';
import AuthLayout from '@/layouts/auth-layout';

export default function Login({ status }: { status?: string }) {
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
        <AuthLayout title="Log in" description="Enter your email and password to sign in.">
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

                <div className="flex flex-col gap-1">
                    <div className="flex items-center justify-between">
                        <Label htmlFor="password">Password</Label>
                        <AuthLink href="/forgot-password">Forgot password?</AuthLink>
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

                <label className="flex items-center gap-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    <input type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                    Remember me
                </label>

                <PrimaryButton type="submit" disabled={processing} className="mt-2">
                    Log in
                </PrimaryButton>
            </form>

            <p className="mt-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Don't have an account? <AuthLink href="/register">Sign up</AuthLink>
            </p>
        </AuthLayout>
    );
}
