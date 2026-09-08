import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode } from 'react';
import type { Auth } from '@/types/auth';

function NavLink({ href, children }: { href: string; children: ReactNode }) {
    const { url } = usePage();
    const active = url === href || url.startsWith(`${href}?`);

    return (
        <Link
            href={href}
            className={
                active
                    ? 'text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]'
                    : 'text-sm font-medium text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]'
            }
        >
            {children}
        </Link>
    );
}

export default function AppLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<{ auth: Auth }>().props;

    return (
        <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
            <nav className="border-b border-[#e3e3e0] dark:border-[#3E3E3A]">
                <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-4 px-6 py-4">
                    <div className="flex flex-wrap items-center gap-6">
                        <span className="font-medium">Skatepark</span>
                        <NavLink href="/dashboard">Dashboard</NavLink>
                        <NavLink href="/subscriptions">Subscriptions</NavLink>
                        {auth.user.role === 'staff' && (
                            <NavLink href="/staff/scan">Scan</NavLink>
                        )}
                        {auth.user.role === 'staff' && (
                            <a
                                href="/admin"
                                className="text-sm font-medium text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                            >
                                Admin
                            </a>
                        )}
                    </div>

                    <div className="flex items-center gap-6">
                        <NavLink href="/settings/password">
                            Change password
                        </NavLink>
                        <button
                            onClick={() => router.post('/logout')}
                            className="text-sm font-medium text-[#f53003] dark:text-[#FF4433]"
                        >
                            Log out
                        </button>
                    </div>
                </div>
            </nav>

            <main>{children}</main>
        </div>
    );
}
