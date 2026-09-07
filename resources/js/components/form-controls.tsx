import { Link } from '@inertiajs/react';
import { ButtonHTMLAttributes, InputHTMLAttributes, LabelHTMLAttributes } from 'react';

export function Label({ className = '', ...props }: LabelHTMLAttributes<HTMLLabelElement>) {
    return <label {...props} className={`text-sm text-[#706f6c] dark:text-[#A1A09A] ${className}`} />;
}

export function TextInput({ className = '', ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            className={`w-full rounded-md border border-[#e3e3e0] bg-transparent px-3 py-2 text-sm outline-none focus:border-[#1b1b18] dark:border-[#3E3E3A] dark:focus:border-[#EDEDEC] ${className}`}
        />
    );
}

export function InputError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="text-sm text-[#f53003] dark:text-[#FF4433]">{message}</p>;
}

export function StatusMessage({ status }: { status?: string | null }) {
    if (!status) {
        return null;
    }

    return <p className="mb-4 text-sm font-medium text-green-600 dark:text-green-500">{status}</p>;
}

export function PrimaryButton({ className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            className={`rounded-md bg-[#1b1b18] px-3 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18] ${className}`}
        />
    );
}

export function AuthLink(props: React.ComponentProps<typeof Link>) {
    return <Link {...props} className={`text-sm font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433] ${props.className ?? ''}`} />;
}
