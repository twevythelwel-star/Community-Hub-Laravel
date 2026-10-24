import { router, usePage } from '@inertiajs/react';

/**
 * Inertia-backed replacement for BillingProvider.
 *
 * The original held `monthlyFee` in React state initialised to 5000, so it reset
 * on every page load and an administrator's change was never persisted or seen by
 * anyone else. The fee is now the `billing_settings` row, shared on every Inertia
 * response.
 */

export type BillingSettings = {
    monthlyFee: number;
    currency: string;
    dueDayOfMonth: number;
};

type SharedProps = { billing?: Partial<BillingSettings> };

const DEFAULTS: BillingSettings = {
    monthlyFee: 5000,
    currency: 'JMD',
    dueDayOfMonth: 1,
};

/**
 * Indicative conversion rates, carried over unchanged from the original.
 *
 * These are static figures used only to show a donor roughly what a JMD goal is
 * worth in their own currency — they are not live rates and must not be used to
 * settle a transaction. Donations are stored in the currency they were made in
 * (`donations.currency`), so nothing is ever converted for accounting. Wire this
 * to a rates provider before quoting a price in a foreign currency.
 */
export const MOCK_EXCHANGE_RATES = {
    JMD_TO_USD: 0.0064,
    JMD_TO_GBP: 0.0051,
    JMD_TO_EUR: 0.006,
    JMD_TO_CAD: 0.0088,
    USD_TO_JMD: 155.5,
    GBP_TO_JMD: 196.8,
    EUR_TO_JMD: 167.9,
    CAD_TO_JMD: 114.1,
};

export function useBilling() {
    const page = usePage<SharedProps>();
    const shared = page.props.billing;

    const monthlyFee = shared?.monthlyFee ?? DEFAULTS.monthlyFee;

    return {
        monthlyFee,
        currency: shared?.currency ?? DEFAULTS.currency,
        dueDayOfMonth: shared?.dueDayOfMonth ?? DEFAULTS.dueDayOfMonth,

        /**
         * Persists the community fee. Requires the `manageBilling` gate;
         * BillingController rejects anyone else.
         */
        setMonthlyFee: (fee: number, options?: { onSuccess?: () => void }) =>
            router.patch(
                '/dashboard/billing/settings',
                {
                    monthly_fee: fee,
                    currency: shared?.currency ?? DEFAULTS.currency,
                    due_day_of_month: shared?.dueDayOfMonth ?? DEFAULTS.dueDayOfMonth,
                },
                { preserveScroll: true, onSuccess: options?.onSuccess },
            ),
    };
}
