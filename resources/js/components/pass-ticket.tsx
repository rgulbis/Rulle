import { ReactNode } from 'react';
import { CheckIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';
import { Plan, useFormatEuros, usePlanText } from '@/lib/plans';

const TILTS = [
    '-rotate-[1.5deg]',
    'rotate-1',
    '-rotate-[0.5deg]',
    'rotate-[0.5deg]',
];

/**
 * A pass drawn as a ticket: price up top, a dashed tear line with
 * punched-out notches, and the action on the stub. Tickets sit slightly
 * askew and lift on hover — deliberately not de-rotating too: animating a
 * rotated dashed border back to level makes the dashes visibly judder as
 * the rotation transitions, most noticeable on this long a line.
 */
export default function PassTicket({
    plan,
    index,
    featured = false,
    action,
}: {
    plan: Plan;
    /** Position in the row — picks the tilt so neighbours differ. */
    index: number;
    featured?: boolean;
    action: ReactNode;
}) {
    const { t } = useTranslation();
    const text = usePlanText();
    const formatEuros = useFormatEuros();
    const description = text.description(plan);
    const entries = text.entries(plan);
    const period = text.period(plan.billing_interval);

    const surface = featured
        ? 'bg-[#16161a] text-[#f7f6f2] hover:shadow-hard-accent-lg'
        : 'bg-paper text-ink hover:shadow-hard-lg';
    const subtle = featured ? 'text-[#b9b8b2]' : 'text-muted';
    const check = featured ? 'text-accent' : 'text-ink';

    return (
        <article
            className={`border-ink flex flex-col border-2 transition duration-200 hover:-translate-x-1 hover:-translate-y-1 ${TILTS[index % TILTS.length]} ${surface}`}
        >
            <div className="flex flex-1 flex-col gap-6 p-7 lg:p-9">
                <div className="flex items-center justify-between gap-3">
                    <h3 className="font-mono text-sm font-semibold tracking-[0.12em] uppercase">
                        {text.name(plan)}
                    </h3>
                    {featured && (
                        <span className="bg-accent text-accent-ink px-2.5 py-1 font-mono text-xs font-semibold tracking-[0.08em] uppercase">
                            {t('subscriptions.mostPicked')}
                        </span>
                    )}
                </div>
                <p className="flex flex-wrap items-baseline gap-2">
                    <span className="font-display text-6xl leading-none font-black lg:text-7xl">
                        {formatEuros(plan.price_cents)}
                    </span>
                    {period && (
                        <span className={`text-lg ${subtle}`}>{period}</span>
                    )}
                </p>
                <ul className="flex flex-1 flex-col gap-3 text-base">
                    {entries && (
                        <li className="flex items-center gap-3">
                            <CheckIcon className={check} />
                            {entries}
                        </li>
                    )}
                    {plan.billing_interval !== 'one_time' && (
                        <li className="flex items-center gap-3">
                            <CheckIcon className={check} />
                            {t('subscriptions.cancelAnyTime')}
                        </li>
                    )}
                    {description && (
                        <li className={`text-base ${subtle}`}>{description}</li>
                    )}
                </ul>
            </div>
            <div className="relative flex flex-wrap items-center justify-between gap-4 border-t-[3px] border-dashed border-current px-7 py-5 lg:px-9">
                <span
                    aria-hidden="true"
                    className="border-ink bg-ground absolute -top-4 -left-4 size-7 rounded-full border-2 [clip-path:inset(0_0_0_50%)]"
                />
                <span
                    aria-hidden="true"
                    className="border-ink bg-ground absolute -top-4 -right-4 size-7 rounded-full border-2 [clip-path:inset(0_50%_0_0)]"
                />
                <span
                    className={`font-mono text-xs font-semibold tracking-[0.12em] uppercase ${subtle}`}
                >
                    {t('subscriptions.admitOne')}
                </span>
                {action}
            </div>
        </article>
    );
}
