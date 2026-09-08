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
            <div className="flex min-h-screen items-center justify-center bg-[#FDFDFC] p-6 text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <div className="w-full max-w-sm rounded-lg bg-white p-8 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]">
                    <div className="mb-6">
                        <h1 className="text-lg font-medium">{title}</h1>
                        {description && (
                            <p className="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
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
