<?php

namespace App\Events\Billing;

use App\Services\Billing\AssessmentGenerationResult;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssessmentInvoicesGeneratedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AssessmentGenerationResult $result
    ) {}
}
