<?php

namespace App\Exceptions;

use App\Enums\PassStatus;
use App\Models\GatePass;
use DomainException;

/** A gate pass was asked to move to a state its current state cannot reach. */
class InvalidPassTransition extends DomainException
{
    public static function between(GatePass $pass, PassStatus $to): self
    {
        return new self(sprintf(
            'Pass %s cannot go from %s to %s.',
            $pass->pass_id,
            $pass->status->label(),
            $to->label(),
        ));
    }
}
