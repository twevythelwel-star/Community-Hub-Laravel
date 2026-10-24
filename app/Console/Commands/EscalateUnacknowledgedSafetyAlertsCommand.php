<?php

namespace App\Console\Commands;

use App\Services\Safety\SilentAssistanceService;
use Illuminate\Console\Command;

class EscalateUnacknowledgedSafetyAlertsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'safety:escalate-unacknowledged';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically escalate unacknowledged distress alerts past their deadline to security escalation';

    /**
     * Execute the console command.
     */
    public function handle(SilentAssistanceService $safety): int
    {
        $count = $safety->escalateUnacknowledgedAlerts();

        if ($count > 0) {
            $this->warn(sprintf('Escalated %d unacknowledged distress alert(s) to Level 2 security escalation.', $count));
        } else {
            $this->info('No unacknowledged distress alerts exceeded their deadline.');
        }

        return self::SUCCESS;
    }
}
