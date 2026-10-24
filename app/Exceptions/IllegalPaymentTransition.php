<?php

namespace App\Exceptions;

use App\Enums\PaymentState;
use App\Models\Payment;
use DomainException;

/** A payment was asked to move to a state PaymentState does not allow from where it is. */
class IllegalPaymentTransition extends DomainException
{
    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentState $to,
    ) {
        parent::__construct(sprintf(
            'Payment #%d is %s and cannot become %s.',
            $payment->id,
            $payment->state->label(),
            $to->label(),
        ));
    }
}
