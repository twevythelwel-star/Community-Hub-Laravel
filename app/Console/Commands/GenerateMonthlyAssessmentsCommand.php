<?php

namespace App\Console\Commands;

use App\Services\Billing\AssessmentBillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateMonthlyAssessmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:generate-assessments
                            {--month= : Target billing month in YYYY-MM format (defaults to current month)}
                            {--community= : Filter by community ID, code or name}
                            {--amount= : Override monthly assessment amount in minor units (e.g., 500000 for 5,000.00)}
                            {--due-day= : Override due day of month (1-28)}
                            {--dry-run : Simulate generation without creating database records}
                            {--force : Force regeneration even if invoices already exist for this month}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the monthly HOA assessments, one per owned property, with payment links and owner notifications';

    /**
     * Execute the console command.
     */
    public function handle(AssessmentBillingService $billingService): int
    {
        $monthOption = $this->option('month');
        $communityOption = $this->option('community');
        $amountOption = $this->option('amount') ? (int) $this->option('amount') : null;
        $dueDayOption = $this->option('due-day') ? (int) $this->option('due-day') : null;
        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');

        $targetDate = $monthOption ? Carbon::parse($monthOption) : Carbon::now();

        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║          AUTOMATED HOA MONTHLY ASSESSMENT ENGINE             ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->line(sprintf(' Billing Period:  <comment>%s</comment>', $targetDate->format('F Y')));
        if ($communityOption) {
            $this->line(sprintf(' Community:       <comment>%s</comment>', $communityOption));
        }
        if ($isDryRun) {
            $this->warn(' MODE:            DRY-RUN (Simulating; no database mutations)');
        }
        if ($isForce) {
            $this->warn(' FORCE:           ENABLED (Overriding deduplication checks)');
        }
        $this->newLine();

        $startTime = microtime(true);

        $result = $billingService->generateMonthlyAssessments(
            targetMonth: $targetDate,
            community: $communityOption,
            amountMinor: $amountOption,
            dueDay: $dueDayOption,
            dryRun: $isDryRun,
            force: $isForce
        );

        $elapsedSeconds = round(microtime(true) - $startTime, 2);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Billing Period', $result->billingPeriod],
                ['Billable Properties', $result->totalEligible],
                ['Invoices Generated', $result->invoicesGenerated],
                ['Invoices Skipped (Already Billed)', $result->invoicesSkipped],
                ['AutoPay Enrolled Owners', $result->autoPayEnrolledCount],
                ['Homeowners Without a Property (Not Billed)', count($result->ownersWithoutProperty)],
                ['Total Amount Assessed', sprintf('%s %s', number_format($result->totalBilledMajor(), 2), $result->currency)],
                ['Status', $result->isDryRun ? 'SIMULATED (DRY RUN)' : 'COMMITTED TO LEDGER'],
                ['Execution Time', sprintf('%s seconds', $elapsedSeconds)],
            ]
        );

        // Not a failure: there is nothing to bill until the office records
        // which property each of them owns, on the Directory page.
        if (! empty($result->ownersWithoutProperty)) {
            $this->newLine();
            $this->warn(sprintf(
                '%d homeowner(s) have no property on record and were not billed (user IDs: %s). Add their properties in the Directory.',
                count($result->ownersWithoutProperty),
                implode(', ', $result->ownersWithoutProperty)
            ));
        }

        if (! empty($result->errors)) {
            $this->newLine();
            $this->error('The following errors occurred during generation:');
            foreach ($result->errors as $error) {
                $this->line("  • {$error}");
            }

            return Command::FAILURE;
        }

        $this->newLine();
        if ($result->isDryRun) {
            $this->comment('Simulation complete. To commit invoices to the ledger, run without --dry-run.');
        } else {
            $this->info(sprintf('Successfully processed %d monthly assessment invoice(s)!', $result->invoicesGenerated));
        }

        return Command::SUCCESS;
    }
}
