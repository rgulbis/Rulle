import { Head } from '@inertiajs/react';
import { LinkButton } from '@/components/form-controls';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';
import { useFormatEuros } from '@/lib/plans';

type Props = {
    groupBooking: {
        price_cents_per_person_per_hour: number;
        min_group_size: number;
    };
};

export default function Groups({ groupBooking }: Props) {
    const { t } = useTranslation();
    const formatEuros = useFormatEuros();

    return (
        <AppLayout>
            <Head title={t('groups.title')} />

            <section className="border-ink bg-accent text-accent-ink border-b-2">
                <div className="mx-auto flex max-w-7xl flex-col gap-7 px-5 py-20 lg:px-10 lg:py-24">
                    <h1 className="font-display text-6xl leading-none font-black uppercase lg:text-9xl">
                        {t('home.crewLead')}
                        <br />
                        {t('home.crewTail')}
                    </h1>
                    <p className="max-w-xl text-xl leading-relaxed">
                        {t('home.crewText', {
                            price: formatEuros(
                                groupBooking.price_cents_per_person_per_hour,
                            ),
                            min: groupBooking.min_group_size,
                        })}
                    </p>
                    <LinkButton
                        href="/reservations"
                        className="shadow-hard-paper self-start border-[#16161a] bg-[#16161a] text-[#f7f6f2]"
                    >
                        {t('home.bookSlot')}
                    </LinkButton>
                </div>
            </section>
        </AppLayout>
    );
}
