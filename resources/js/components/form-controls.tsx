import { Link } from '@inertiajs/react';
import {
    ButtonHTMLAttributes,
    InputHTMLAttributes,
    LabelHTMLAttributes,
} from 'react';

export function Label({
    className = '',
    ...props
}: LabelHTMLAttributes<HTMLLabelElement>) {
    return (
        <label
            {...props}
            className={`text-sm font-medium text-gray-700 ${className}`}
        />
    );
}

export function TextInput({
    className = '',
    ...props
}: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            className={`w-full rounded-none border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition outline-none placeholder:text-gray-400 focus:border-yellow-500 focus:ring-4 focus:ring-yellow-500/20 ${className}`}
        />
    );
}

export function InputError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="text-sm text-red-600">{message}</p>;
}

export function StatusMessage({ status }: { status?: string | null }) {
    if (!status) {
        return null;
    }

    return (
        <p className="mb-4 rounded-none border border-green-200 bg-green-50 px-3 py-2 text-sm font-medium text-green-700">
            {status}
        </p>
    );
}

export function PrimaryButton({
    className = '',
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            className={`rounded-none bg-yellow-400 px-4 py-2 text-sm font-semibold text-black shadow-sm transition hover:bg-yellow-300 focus:ring-4 focus:ring-yellow-500/40 disabled:cursor-not-allowed disabled:opacity-50 ${className}`}
        />
    );
}

export function AuthLink(props: React.ComponentProps<typeof Link>) {
    return (
        <Link
            {...props}
            className={`text-sm font-semibold text-yellow-700 hover:text-yellow-600 ${props.className ?? ''}`}
        />
    );
}
