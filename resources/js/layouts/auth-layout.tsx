import { Head } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function AuthLayout({
    title,
    description,
    children,
}: PropsWithChildren<{ title: string; description?: string }>) {
    return (
        <>
            <Head title={title} />
            <div className="flex min-h-screen items-center justify-center bg-gray-50 p-6">
                <div className="w-full max-w-sm rounded-none border border-gray-300 bg-white p-8 shadow-sm">
                    <div className="mb-6">
                        <h1 className="text-xl font-semibold text-gray-900">
                            {title}
                        </h1>
                        {description && (
                            <p className="mt-1 text-sm text-gray-500">
                                {description}
                            </p>
                        )}
                    </div>
                    {children}
                </div>
            </div>
        </>
    );
}
