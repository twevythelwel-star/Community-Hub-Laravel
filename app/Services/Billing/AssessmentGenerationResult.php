<?php

namespace App\Services\Billing;

class AssessmentGenerationResult
{
    /**
     * Dues are per property: "eligible" and "skipped" count properties.
     *
     * @param  list<int>  $generatedInvoiceIds
     * @param  list<int>  $skippedPropertyIds  already billed this period
     * @param  list<string>  $errors
     * @param  list<int>  $ownersWithoutProperty  homeowners with no property on record, so not billed
     */
    public function __construct(
        public readonly string $billingPeriod,
        public readonly int $totalEligible,
        public readonly int $invoicesGenerated,
        public readonly int $invoicesSkipped,
        public readonly int $totalBilledMinor,
        public readonly string $currency,
        public readonly int $autoPayEnrolledCount,
        public readonly array $generatedInvoiceIds = [],
        public readonly array $skippedPropertyIds = [],
        public readonly array $errors = [],
        public readonly bool $isDryRun = false,
        public readonly array $ownersWithoutProperty = [],
    ) {}

    public function totalBilledMajor(): float
    {
        return (float) ($this->totalBilledMinor / 100);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'billing_period' => $this->billingPeriod,
            'is_dry_run' => $this->isDryRun,
            'total_eligible' => $this->totalEligible,
            'invoices_generated' => $this->invoicesGenerated,
            'invoices_skipped' => $this->invoicesSkipped,
            'total_billed_minor' => $this->totalBilledMinor,
            'total_billed_major' => $this->totalBilledMajor(),
            'currency' => $this->currency,
            'autopay_enrolled_count' => $this->autoPayEnrolledCount,
            'generated_invoice_ids_count' => count($this->generatedInvoiceIds),
            'owners_without_property_count' => count($this->ownersWithoutProperty),
            'errors' => $this->errors,
        ];
    }
}
