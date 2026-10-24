<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Public payment links
    |---------------------------------------------------------------------------
    |
    | Off by default, deliberately.
    |
    | `UniversalPaymentLinkController` serves an unauthenticated page that
    | anyone holding the link can submit. Its payments are now recorded as
    | `pending` and count only once an administrator confirms them from the
    | billing ledger, so a submission can no longer settle anything by itself.
    |
    | It stays off because the page is not ready: its form still posts a card
    | channel it has no checkout for, so it needs a "report a payment" form
    | offering the office-confirmed channels before it is switched on.
    |
    */

    'public_links_enabled' => (bool) env('PAYMENT_PUBLIC_LINKS_ENABLED', false),

    /*
    |---------------------------------------------------------------------------
    | Card payments: which processor takes them
    |---------------------------------------------------------------------------
    |
    | `stripe` or `wipay`. Card payment is offered only when a processor is
    | configured: left empty, Stripe is used if its keys are set (for estates
    | where Stripe supports the merchant), and otherwise card is unavailable.
    | Nothing is hard-wired to a processor; see
    | App\Services\Payments\Providers\ProviderRegistry.
    |
    */

    'card_provider' => env('PAYMENTS_CARD_PROVIDER'),

    /*
    |---------------------------------------------------------------------------
    | Device wallets this estate's merchant account can accept
    |---------------------------------------------------------------------------
    |
    | A processor supporting Apple Pay or Google Pay in general does not mean
    | this estate's merchant account can take it: the wallet has to be
    | enabled and approved for the account, in the country it trades in.
    | List a wallet here only once that is validated with the processor
    | (e.g. "apple_pay,google_pay"). A wallet is offered only when it is
    | listed here AND the configured card processor supports it; empty
    | offers none.
    |
    */

    /*
    |---------------------------------------------------------------------------
    | In-person (card-present / NFC) payments
    |---------------------------------------------------------------------------
    |
    | The provider whose readers take taps and card inserts at the office:
    | `stripe_terminal`, for estates whose Stripe account and reader locations
    | are in a Stripe Terminal country (not Jamaica). Empty: in-person card
    | payments are off. A reader must also be registered and confirmed with
    | the provider before it can take a payment.
    |
    */

    'in_person_provider' => env('PAYMENTS_IN_PERSON_PROVIDER'),

    'wallets' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYMENTS_WALLETS', ''))))),

    'default_gateway' => env('PAYMENTS_DEFAULT_GATEWAY', 'stripe'),

    'providers' => [

        /*
         | WiPay hosted payment page (Payments API 1.0.8). Sandbox: account
         | number 1234567890, API key 123. The API key signs the result WiPay
         | sends back; it never leaves the server.
         */
        'wipay' => [
            'account_number' => env('WIPAY_ACCOUNT_NUMBER'),
            'api_key' => env('WIPAY_API_KEY'),
            'environment' => env('WIPAY_ENVIRONMENT', 'sandbox'),   // sandbox | live
            'country_code' => env('WIPAY_COUNTRY_CODE', 'JM'),      // BB | JM | TT
            'fee_structure' => env('WIPAY_FEE_STRUCTURE', 'merchant_absorb'), // customer_pay | merchant_absorb | split
            'origin' => env('WIPAY_ORIGIN', 'CommunityHub'),
        ],

        /*
         | PayPal has no provider here. Only UniversalPaymentWebhookService
         | reads this block, for its webhook signature check, and it reads
         | `webhook_secret`, which is not defined below.
         */
        'paypal' => [
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'secret' => env('PAYPAL_SECRET'),
            'mode' => env('PAYPAL_MODE', 'sandbox'), // sandbox | live
        ],
    ],

];
