import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    InputError,
    Label,
    PrimaryButton,
    TextInput,
} from '@/components/form-controls';
import AuthLayout from '@/layouts/auth-layout';

export default function ResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/reset-password', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title="Reset password"
            description="Enter your new password below."
        >
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <Label htmlFor="email">Email</Label>
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
                    <Label htmlFor="password">New password</Label>
                    <TextInput
                        id="password"
                        type="password"
                        autoFocus
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
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
                    Reset password
                </PrimaryButton>
            </form>
        </AuthLayout>
    );
}
