import { useTranslation } from '@/lib/i18n/context';

/** "17/09/2026 16:00 – 17:30" in the viewer's locale. */
export function useFormatRange() {
    const { intlLocale } = useTranslation();

    return (startsAt: string, endsAt: string) => {
        const start = new Date(startsAt);
        const end = new Date(endsAt);
        const time: Intl.DateTimeFormatOptions = {
            hour: '2-digit',
            minute: '2-digit',
        };

        return `${start.toLocaleDateString(intlLocale)} ${start.toLocaleTimeString(intlLocale, time)} – ${end.toLocaleTimeString(intlLocale, time)}`;
    };
}
