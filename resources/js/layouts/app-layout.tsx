import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode } from 'react';
import type { User } from '@/types/auth';

function NavLink({ href, children }: { href: string; children: ReactNode }) {
    const { url } = usePage();
    const active = url === href || url.startsWith(`${href}?`);

    return (
        <Link
            href={href}
            className={
                active
                    ? 'border-b-2 border-yellow-400 text-sm font-semibold text-gray-900'
                    : 'text-sm font-medium text-gray-500 hover:text-gray-900'
            }
        >
            {children}
        </Link>
    );
}

export default function AppLayout({ children }: PropsWithChildren) {
    // Wider than the app-wide `Auth` type on purpose: this is the one layout
    // a guest can land on too (the public livestream page uses it), so the
    // user here really can be null, unlike every other page that renders it.
    const { auth } = usePage<{ auth: { user: User | null } }>().props;
    const user = auth.user;

    return (
        <div className="min-h-screen bg-gray-50 text-gray-900">
            <nav className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-4 px-6 py-4">
                    <div className="flex flex-wrap items-center gap-6">
                        <span className="font-semibold text-gray-900">
                            Rullē
                        </span>
                        {user?.role === 'user' && (
                            <>
                                <NavLink href="/dashboard">Dashboard</NavLink>
                                <NavLink href="/subscriptions">
                                    Subscriptions
                                </NavLink>
                                <NavLink href="/reservations">
                                    Reservations
                                </NavLink>
                            </>
                        )}
                        {(user?.role === 'admin' ||
                            user?.role === 'employee') && (
                            <NavLink href="/staff/scan">Scan</NavLink>
                        )}
                        {user && <NavLink href="/chat">Chat</NavLink>}
                        <NavLink href="/livestream">Livestream</NavLink>
                        {user?.role === 'admin' && (
                            <a
                                href="/admin"
                                className="text-sm font-medium text-gray-500 hover:text-gray-900"
                            >
                                Admin
                            </a>
                        )}
                    </div>

                    <div className="flex items-center gap-6">
                        {user ? (
                            <>
                                <NavLink href="/settings/password">
                                    Change password
                                </NavLink>
                                <button
                                    onClick={() => router.post('/logout')}
                                    className="text-sm font-medium text-red-600 hover:text-red-500"
                                >
                                    Log out
                                </button>
                            </>
                        ) : (
                            <NavLink href="/login">Log in</NavLink>
                        )}
                    </div>
                </div>
            </nav>

            <main>{children}</main>
        </div>
    );
}
