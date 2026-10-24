<?php

namespace App\Services\Billing;

use App\Enums\UserRole;
use App\Events\Billing\AssessmentInvoicesGeneratedEvent;
use App\Models\BillingSetting;
use App\Models\Community;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Property;
use App\Models\User;
use App\Services\PropertyOwnershipService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssessmentBillingService
{
    public function __construct(private readonly PropertyOwnershipService $ownership) {}

    /**
     * Generate the month's HOA assessments: one invoice per owned property,
     * billed to its owner. An owner of three lots gets three invoices.
     *
     * Renters (Temporary Homeowners) are not billed: their stay is at a
     * property its owner already pays for. They used to get an invoice of
     * their own as well.
     *
     * @param  CarbonInterface|string|null  $targetMonth  e.g. '2026-10' or Carbon instance
     * @param  int|string|null  $community  Community ID, code or name
     * @param  int|null  $amountMinor  Override fee in minor units (e.g. 500000 = $5,000.00)
     * @param  int|null  $dueDay  Override due day of month (1-28)
     * @param  bool  $dryRun  If true, simulate without modifying the database
     * @param  bool  $force  If true, force generation even if already billed for period
     */
    public function generateMonthlyAssessments(
        CarbonInterface|string|null $targetMonth = null,
        int|string|null $community = null,
        ?int $amountMinor = null,
        ?int $dueDay = null,
        bool $dryRun = false,
        bool $force = false
    ): AssessmentGenerationResult {
        $monthDate = $targetMonth ? Carbon::parse($targetMonth) : Carbon::now();
        $periodStart = $monthDate->copy()->startOfMonth()->startOfDay();
        $periodEnd = $monthDate->copy()->endOfMonth()->startOfDay();

        // Load billing setting defaults
        $billingSetting = BillingSetting::current();
        $feeMinor = $amountMinor ?? $billingSetting->monthly_fee_minor ?? 500000;
        $currency = $billingSetting->currency ?? 'JMD';
        $dueDayResolved = $dueDay ?? $billingSetting->due_day_of_month ?? 1;

        $clampedDueDay = min($dueDayResolved, (int) $periodEnd->format('d'));
        $dueOn = $monthDate->copy()->day($clampedDueDay)->startOfDay();

        // Resolve community if specified. Accounts carry no community; a
        // property does, so the filter is on the property.
        $resolvedCommunityId = null;
        if ($community) {
            $communityModel = is_numeric($community)
                ? Community::find((int) $community)
                // communities has no slug column; it has a code.
                : Community::where('code', $community)->orWhere('name', $community)->first();

            if ($communityModel) {
                $resolvedCommunityId = $communityModel->id;
            }
        }

        [$billables, $ownersWithoutProperty] = $this->billableProperties($resolvedCommunityId, $dryRun);

        $generatedInvoiceIds = [];
        $skippedPropertyIds = [];
        $errors = [];
        $totalBilledMinor = 0;
        $autoPayOwnerIds = [];
        $legacyCovered = [];

        foreach ($billables as ['owner' => $owner, 'property' => $property]) {
            if (! $force && $this->alreadyBilled($owner, $property, $periodStart, $periodEnd, $legacyCovered)) {
                $skippedPropertyIds[] = $property?->id ?? 0;

                continue;
            }

            if ($owner->autoPaySetting?->is_active) {
                $autoPayOwnerIds[$owner->id] = true;
            }

            if ($dryRun) {
                $totalBilledMinor += $feeMinor;
                $generatedInvoiceIds[] = -($property?->id ?? $owner->id); // Negative placeholder ID for simulation

                continue;
            }

            try {
                DB::transaction(function () use (
                    $owner,
                    $property,
                    $periodStart,
                    $periodEnd,
                    $dueOn,
                    $feeMinor,
                    $currency,
                    &$generatedInvoiceIds,
                    &$totalBilledMinor
                ) {
                    $propertyLabel = $property->label();

                    $invoice = Invoice::create([
                        'user_id' => $owner->id,
                        'property_id' => $property->id,
                        'reference' => sprintf(
                            'INV-%s-%s-%s',
                            $periodStart->format('Ym'),
                            $property->property_code,
                            strtoupper(bin2hex(random_bytes(2)))
                        ),
                        'amount_minor' => $feeMinor,
                        'currency' => $currency,
                        'period_start' => $periodStart->toDateString(),
                        'period_end' => $periodEnd->toDateString(),
                        'due_on' => $dueOn->toDateString(),
                        'status' => 'Unpaid',
                    ]);

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'category' => 'hoa_dues',
                        'title' => sprintf('Monthly HOA Assessment - %s (%s)', $periodStart->format('F Y'), $propertyLabel),
                        'amount_minor' => $feeMinor,
                        'status' => 'Unpaid',
                    ]);

                    // Generate secure, opaque payment link
                    $paymentLink = $invoice->generatePaymentLink(
                        expiresAt: $dueOn->copy()->endOfDay()
                    );

                    // Notify the owner, naming the property: one may hold several.
                    InAppNotification::create([
                        'user_id' => $owner->id,
                        'tenant_id' => $owner->tenant_id ?? null,
                        'category' => 'billing',
                        'title' => sprintf('Monthly Assessment Invoice Generated (%s)', $periodStart->format('F Y')),
                        'body' => sprintf(
                            'Your monthly HOA maintenance fee for %s of %s %s is due on %s.',
                            $propertyLabel,
                            number_format($feeMinor / 100, 2),
                            $currency,
                            $dueOn->format('M d, Y')
                        ),
                        'action_url' => '/portal/billing',
                        'priority' => 'high',
                        'data' => [
                            'invoice_id' => $invoice->id,
                            'invoice_reference' => $invoice->reference,
                            'property_id' => $property->id,
                            'property' => $propertyLabel,
                            'amount_minor' => $feeMinor,
                            'currency' => $currency,
                            'due_on' => $dueOn->toDateString(),
                            'payment_link_token' => $paymentLink->token,
                        ],
                    ]);

                    // Update next run on AutoPay setting if active
                    if ($owner->autoPaySetting?->is_active) {
                        $owner->autoPaySetting->update([
                            'next_run_at' => $dueOn,
                        ]);
                    }

                    $generatedInvoiceIds[] = $invoice->id;
                    $totalBilledMinor += $feeMinor;
                });
            } catch (\Throwable $e) {
                $errors[] = sprintf('Failed to invoice %s for user #%d (%s): %s', $property->label(), $owner->id, $owner->name, $e->getMessage());
                Log::channel('daily')->error('Assessment invoice generation failure', [
                    'user_id' => $owner->id,
                    'property_id' => $property->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $result = new AssessmentGenerationResult(
            billingPeriod: $periodStart->format('Y-m'),
            totalEligible: count($billables),
            invoicesGenerated: count($generatedInvoiceIds),
            invoicesSkipped: count($skippedPropertyIds),
            totalBilledMinor: $totalBilledMinor,
            currency: $currency,
            autoPayEnrolledCount: count($autoPayOwnerIds),
            generatedInvoiceIds: $generatedInvoiceIds,
            skippedPropertyIds: $skippedPropertyIds,
            errors: $errors,
            isDryRun: $dryRun,
            ownersWithoutProperty: $ownersWithoutProperty,
        );

        if (! $dryRun && count($generatedInvoiceIds) > 0) {
            AssessmentInvoicesGeneratedEvent::dispatch($result);
            Log::info('Automated monthly assessments generated', $result->toArray());
        }

        return $result;
    }

    /**
     * Every owned property, with its owner. A homeowner whose only record of
     * their property is the lot on their account has it recorded first (in a
     * dry run it is counted, unsaved, as `property => null`).
     *
     * @return array{0: list<array{owner: User, property: ?Property}>, 1: list<int>}
     */
    private function billableProperties(?int $communityId, bool $dryRun): array
    {
        $billables = [];
        $ownersWithoutProperty = [];

        if ($communityId === null) {
            $unrecorded = User::query()
                ->where('role', UserRole::Homeowner->value)
                ->whereDoesntHave('properties')
                ->with('autoPaySetting')
                ->get();

            foreach ($unrecorded as $owner) {
                if (blank($owner->lot) || $this->ownership->lotOwnedByAnother($owner)) {
                    // Nothing of theirs to bill. A lot someone else owns (two
                    // accounts at one address) is billed once, to its owner.
                    $ownersWithoutProperty[] = $owner->id;
                } elseif ($dryRun) {
                    $billables[] = ['owner' => $owner, 'property' => null];
                } else {
                    $this->ownership->recordAccountProperty($owner);
                }
            }
        }

        $properties = Property::query()
            ->whereHas('owner')
            ->when($communityId, fn ($q) => $q->where('community_id', $communityId))
            ->with('owner.autoPaySetting')
            ->orderBy('id')
            ->get();

        foreach ($properties as $property) {
            $billables[] = ['owner' => $property->owner, 'property' => $property];
        }

        return [$billables, $ownersWithoutProperty];
    }

    /**
     * Already invoiced for this property this period. Invoices from before
     * dues were per property carry no property; one of those covers the
     * owner's first property, so a re-run does not bill it twice.
     *
     * @param  array<int, bool>  $legacyCovered  owners whose pre-property invoice has been counted
     */
    private function alreadyBilled(User $owner, ?Property $property, CarbonInterface $periodStart, CarbonInterface $periodEnd, array &$legacyCovered): bool
    {
        $forPeriod = fn () => Invoice::query()
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->whereHas('items', fn ($iq) => $iq->where('category', 'hoa_dues'));

        if ($property && $forPeriod()->where('property_id', $property->id)->exists()) {
            return true;
        }

        if (! isset($legacyCovered[$owner->id])
            && $forPeriod()->where('user_id', $owner->id)->whereNull('property_id')->exists()) {
            $legacyCovered[$owner->id] = true;

            return true;
        }

        return false;
    }
}
