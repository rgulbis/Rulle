import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import {
    AuthLink,
    InputError,
    Label,
    PrimaryButton,
    TextInput,
} from '@/components/form-controls';
import AuthLayout from '@/layouts/auth-layout';

export default function Register() {
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

    return (
        <AuthLayout
            title="Create an account"
            description="Enter your details below to sign up."
        >
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <Label htmlFor="name">Name</Label>
                    <TextInput
                        id="name"
                        autoFocus
                        autoComplete="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} />
                </div>

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
                    <Label htmlFor="password">Password</Label>
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
                    <Label htmlFor="password_confirmation">
                        Confirm password
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
                    Sign up
                </PrimaryButton>
            </form>

            <p className="mt-6 text-sm text-gray-500">
                Already have an account?{' '}
                <AuthLink href="/login">Log in</AuthLink>
            </p>
        </AuthLayout>
    );
}
