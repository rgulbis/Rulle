import { Head, Link } from '@inertiajs/react';
import ChatThread, { ChatMessage } from '@/components/chat-thread';
import AppLayout from '@/layouts/app-layout';

type Props = {
    reservation: {
        id: number;
        starts_at: string;
        ends_at: string;
    };
    messages: ChatMessage[];
};

function formatRange(startsAt: string, endsAt: string) {
    const start = new Date(startsAt);
    const end = new Date(endsAt);

    return `${start.toLocaleDateString()} ${start.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} – ${end.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}

export default function ReservationChat({ reservation, messages }: Props) {
    return (
        <AppLayout>
            <Head title="Reservation chat" />
            <div className="p-6">
                <div className="mx-auto flex max-w-3xl flex-col gap-4">
                    <div>
                        <Link
                            href="/reservations"
                            className="text-sm text-gray-500 hover:text-gray-900"
                        >
                            ← Back to reservations
                        </Link>
                        <h1 className="mt-1 text-xl font-semibold text-gray-900">
                            Group chat —{' '}
                            {formatRange(
                                reservation.starts_at,
                                reservation.ends_at,
                            )}
                        </h1>
                        <p className="text-sm text-gray-500">
                            Only visible to your group — the owner and the
                            friends they've added.
                        </p>
                    </div>

                    <ChatThread
                        channel={`reservation.${reservation.id}.chat`}
                        initialMessages={messages}
                        postUrl={`/reservations/${reservation.id}/chat`}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
