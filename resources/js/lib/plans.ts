import { useTranslation } from '@/lib/i18n/context';

export type Plan = {
    id: number;
    name: string;
    description: string | null;
    name_lv: string | null;
    description_lv: string | null;
    price_cents: number;
    billing_interval: 'one_time' | 'month' | 'year';
    visit_limit: number | null;
    unlimited_entries: boolean;
};

/** Euro amounts in the visitor's language: "5,00 €" in LV, "€5.00" in EN. */
export function useFormatEuros() {
    const { intlLocale } = useTranslation();

    return (cents: number) =>
        new Intl.NumberFormat(intlLocale, {
            style: 'currency',
            currency: 'EUR',
        }).format(cents / 100);
}

/**
 * Localized plan text. Falls back to the English field when no Latvian
 * translation has been filled in for a plan yet, rather than showing blank.
 */
export function usePlanText() {
    const { t, tCount, locale } = useTranslation();

    const name = (plan: Pick<Plan, 'name' | 'name_lv'>) =>
        locale === 'lv' && plan.name_lv ? plan.name_lv : plan.name;

    const description = (plan: Pick<Plan, 'description' | 'description_lv'>) =>
        locale === 'lv' && plan.description_lv
            ? plan.description_lv
            : plan.description;

    const period = (interval: Plan['billing_interval']) =>
        interval === 'month'
            ? t('subscriptions.perMonthShort')
            : interval === 'year'
              ? t('subscriptions.perYearShort')
              : null;

    const entries = (plan: Plan) =>
        plan.unlimited_entries
            ? plan.billing_interval === 'one_time'
                ? t('subscriptions.unlimitedSameDay')
                : t('subscriptions.unlimitedEntries')
            : plan.visit_limit
              ? tCount('subscriptions.visitsCount', plan.visit_limit)
              : null;

    return { name, description, period, entries };
}
