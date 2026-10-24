<?php

namespace App\Services\Payments\Providers;

use App\Services\Payments\Providers\Contracts\OffersWalletPayments;
use App\Services\Payments\Providers\Contracts\PaymentProvider;
use App\Services\Payments\Providers\Contracts\TakesInPersonPayments;
use App\Services\Payments\Providers\Office\OfficeProvider;
use App\Services\Payments\Providers\Stripe\StripeProvider;
use App\Services\Payments\Providers\StripeTerminal\StripeTerminalProvider;
use App\Services\Payments\Providers\Wallet\WalletProvider;
use App\Services\Payments\Providers\WiPay\WiPayProvider;

/**
 * Which provider handles a channel.
 *
 *   card      the estate's configured card processor (config
 *             payments.card_provider: "stripe" or "wipay"). Left empty,
 *             Stripe is used if its keys are set; otherwise card is off.
 *   apple_pay, google_pay, samsung_wallet
 *             the card processor, if it offers that wallet
 *             (OffersWalletPayments) AND the estate has validated that its
 *             merchant account accepts it (config payments.wallets);
 *             otherwise unavailable. Apple requires the merchant's processor
 *             to support Apple Pay, and Google Pay needs merchant setup with
 *             the processor even where Google Pay itself is available — a
 *             wallet is never something the office can confirm.
 *   nfc_pos   the in-person provider (config payments.in_person_provider),
 *             on a registered, confirmed reader, started by staff — never a
 *             channel residents pick online, and never the office's word.
 *   wallet    the Community Wallet.
 *   anything  else the community office (bank wire, cash, QR, Zelle,
 *             Cash App).
 *
 * Nothing names a processor anywhere else; adding one is a new provider class
 * and a line in providers() — no controller or state-machine change.
 */
class ProviderRegistry
{
    /** Device wallets: taken only through a card processor that offers them. */
    public const WALLET_CHANNELS = ['apple_pay', 'google_pay', 'samsung_wallet'];

    public function __construct(
        protected StripeProvider $stripe,
        protected WiPayProvider $wipay,
        protected OfficeProvider $office,
        protected WalletProvider $wallet,
        protected StripeTerminalProvider $stripeTerminal,
    ) {}

    /** @return array<string, PaymentProvider> keyed by PaymentProvider::key() */
    public function providers(): array
    {
        return [
            $this->stripe->key() => $this->stripe,
            $this->wipay->key() => $this->wipay,
            $this->office->key() => $this->office,
            $this->wallet->key() => $this->wallet,
            $this->stripeTerminal->key() => $this->stripeTerminal,
        ];
    }

    public function byKey(string $key): ?PaymentProvider
    {
        return $this->providers()[$key] ?? null;
    }

    /** The provider for a channel, or null when none is available to take it. */
    public function forChannel(string $channel): ?PaymentProvider
    {
        return match ($channel) {
            'card', 'stripe_card' => $this->cardProvider(),
            'apple_pay', 'google_pay', 'samsung_wallet' => $this->walletProvider($channel),
            'nfc_pos' => $this->inPersonProvider(),
            'wallet' => $this->wallet,
            default => $this->office,
        };
    }

    /**
     * The card processor, if it offers this device wallet and the estate has
     * validated that its merchant account accepts it.
     *
     * Both are needed. The processor's support is general; whether this
     * merchant account, in this country, can take the wallet is confirmed
     * with the processor and recorded in config payments.wallets.
     */
    public function walletProvider(string $channel): ?PaymentProvider
    {
        $provider = $this->cardProvider();

        return $provider instanceof OffersWalletPayments
            && in_array($channel, $provider->walletMethods(), true)
            && in_array($channel, (array) config('payments.wallets', []), true)
            ? $provider
            : null;
    }

    /**
     * The provider whose readers take in-person payments, if one is chosen
     * and set up. It still needs a registered, confirmed reader per payment.
     */
    public function inPersonProvider(): (PaymentProvider&TakesInPersonPayments)|null
    {
        $provider = $this->byKey((string) config('payments.in_person_provider'));

        return $provider instanceof TakesInPersonPayments && $provider->isAvailable() ? $provider : null;
    }

    /** Whether a channel can be offered to payers right now. */
    public function isAvailable(string $channel): bool
    {
        return $this->forChannel($channel) !== null;
    }

    /**
     * The card processor for this estate. A configured one that is not set
     * up (no keys) makes card unavailable rather than falling back to
     * another processor the estate did not choose.
     */
    public function cardProvider(): ?PaymentProvider
    {
        $configured = config('payments.card_provider');

        if (filled($configured)) {
            $provider = $this->byKey((string) $configured);

            return $provider && ! in_array($provider->key(), [OfficeProvider::KEY, WalletProvider::KEY], true) && $provider->isAvailable()
                ? $provider
                : null;
        }

        return $this->stripe->isAvailable() ? $this->stripe : null;
    }
}
