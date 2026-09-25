import { Head, Link } from '@inertiajs/react';
import ChatThread, { ChatMessage } from '@/components/chat-thread';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';

type Props = {
    reservation: {
        id: number;
        starts_at: string;
        ends_at: string;
    };
    messages: ChatMessage[];
};

export default function ReservationChat({ reservation, messages }: Props) {
    const { t, intlLocale } = useTranslation();

    const formatRange = (startsAt: string, endsAt: string) => {
        const start = new Date(startsAt);
        const end = new Date(endsAt);

        return `${start.toLocaleDateString(intlLocale)} ${start.toLocaleTimeString(intlLocale, { hour: '2-digit', minute: '2-digit' })} – ${end.toLocaleTimeString(intlLocale, { hour: '2-digit', minute: '2-digit' })}`;
    };

    return (
        <AppLayout>
            <Head title={t('reservations.groupChat')} />
            <div className="p-6">
                <div className="mx-auto flex max-w-3xl flex-col gap-4">
                    <div>
                        <Link
                            href="/reservations"
                            className="text-sm text-gray-500 hover:text-gray-900"
                        >
                            {t('reservationChat.back')}
                        </Link>
                        <h1 className="mt-1 text-xl font-semibold text-gray-900">
                            {t('reservationChat.title', {
                                range: formatRange(
                                    reservation.starts_at,
                                    reservation.ends_at,
                                ),
                            })}
                        </h1>
                        <p className="text-sm text-gray-500">
                            {t('reservationChat.subtitle')}
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
