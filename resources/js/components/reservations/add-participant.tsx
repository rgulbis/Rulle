import { router } from '@inertiajs/react';
import { useState } from 'react';
import { TextInput } from '@/components/form-controls';
import { useTranslation } from '@/lib/i18n/context';
import type { Participant } from './types';

/** Search box for inviting customers to a paid reservation. */
export function AddParticipant({
    reservationId,
    remainingCapacity,
}: {
    reservationId: number;
    remainingCapacity: number;
}) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Participant[]>([]);
    const [searching, setSearching] = useState(false);

    if (remainingCapacity <= 0) {
        return (
            <p className="text-muted mt-3 text-sm">
                {t('reservations.groupFull')}
            </p>
        );
    }

    const search = async (value: string) => {
        setQuery(value);

        if (value.trim().length < 2) {
            setResults([]);
            return;
        }

        setSearching(true);

        try {
            const response = await fetch(
                `/reservations/users/search?q=${encodeURIComponent(value)}`,
                { headers: { Accept: 'application/json' } },
            );
            setResults(response.ok ? await response.json() : []);
        } finally {
            setSearching(false);
        }
    };

    const add = (userId: number) => {
        router.post(
            `/reservations/${reservationId}/participants`,
            { user_id: userId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setQuery('');
                    setResults([]);
                },
            },
        );
    };

    return (
        <div className="mt-3">
            <TextInput
                value={query}
                onChange={(e) => search(e.target.value)}
                placeholder={t('reservations.searchPlaceholder')}
                className="py-2.5 text-sm"
            />
            {searching && (
                <p className="text-muted mt-1 text-sm">
                    {t('reservations.searching')}
                </p>
            )}
            {results.length > 0 && (
                <ul className="divide-line border-ink bg-paper mt-2 divide-y border-2">
                    {results.map((result) => (
                        <li
                            key={result.id}
                            className="flex items-center justify-between gap-3 px-4 py-2 text-sm"
                        >
                            <span>{result.name}</span>
                            <button
                                type="button"
                                onClick={() => add(result.id)}
                                className="hover:decoration-accent min-h-11 px-2 font-semibold underline decoration-2 underline-offset-4"
                            >
                                {t('reservations.add')}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
