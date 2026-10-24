<?php

namespace App\Services\Payments\Stripe;

use App\Models\Payment;
use App\Models\Transaction;
use Stripe\StripeObject;

class StripeReferenceUtil
{
    /** Stripe fields are either an id string or an expanded object. */
    public static function idOf(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $value instanceof StripeObject ? $value->id : null;
    }

    public static function paymentReference(string $paymentIntent): string
    {
        return "stripe:{$paymentIntent}";
    }

    public static function refundReference(string $paymentIntent, int|string $cumulativeMinor): string
    {
        return "stripe-refund:{$paymentIntent}:{$cumulativeMinor}";
    }

    public static function paymentIntentOf(Transaction $payment): ?string
    {
        return str_starts_with($payment->reference, 'stripe:')
            ? substr($payment->reference, strlen('stripe:'))
            : null;
    }

    /**
     * The payment a Stripe object belongs to: by the payment id we put in its
     * metadata, then by its Checkout Session, then by its PaymentIntent.
     */
    public static function paymentFor(StripeObject|string|null $object): ?Payment
    {
        if ($object === null) {
            return null;
        }

        if (is_string($object)) {
            return Payment::query()->where('provider_payment_id', $object)->first();
        }

        $paymentId = $object->metadata->payment_id ?? null;
        if ($paymentId && $payment = Payment::find((int) $paymentId)) {
            return $payment;
        }

        $chTxId = $object->metadata->communityhub_transaction_id ?? ($object->metadata->transaction ?? null);
        if ($chTxId && $payment = Payment::query()->where('transaction_id', $chTxId)->first()) {
            return $payment;
        }

        $id = (string) $object->id;

        if (str_starts_with($id, 'cs_') && $payment = Payment::query()->where('provider_session_id', $id)->first()) {
            return $payment;
        }

        $paymentIntent = str_starts_with($id, 'pi_') ? $id : self::idOf($object->payment_intent ?? null);

        return $paymentIntent ? Payment::query()->where('provider_payment_id', $paymentIntent)->first() : null;
    }
}
