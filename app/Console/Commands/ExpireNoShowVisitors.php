<?php

namespace App\Console\Commands;

use App\Enums\PassStatus;
use App\Models\Visitor;
use Illuminate\Console\Command;

/**
 * Expires visitors who were expected but never checked in.
 *
 * This replaces the setInterval in src/app/dashboard/visitors/page.tsx, which
 * checked every 60 seconds for visitors more than 12 hours past their expected
 * time and dropped them from React state. Three problems with that:
 *
 *   - it only ran while somebody had the page open;
 *   - it removed rows from one browser's memory, so another user still saw them;
 *   - a genuinely stale pre-clearance stayed valid at the gate indefinitely.
 *
 * Expiry is now a scheduled server sweep, and the records are marked rather than
 * deleted so the history survives for audit.
 */
class ExpireNoShowVisitors extends Command
{
    protected $signature = 'visitors:expire-no-shows
                            {--hours=12 : Grace period after the expected time}
                            {--dry-run : List what would be expired without changing anything}';

    protected $description = 'Expire pre-cleared visitors who never checked in';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');

        $stale = Visitor::noShow($hours)->get();

        if ($stale->isEmpty()) {
            $this->info('No expired visitor clearances.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Visitor', 'Expected', 'Registered by'],
            $stale->map(fn (Visitor $v) => [
                $v->id,
                $v->name,
                $v->expected_at->toDateTimeString(),
                $v->homeowner_name ?? '—',
            ])->all(),
        );

        if ($dryRun) {
            $this->comment(sprintf('Dry run: %d clearance(s) would be expired.', $stale->count()));

            return self::SUCCESS;
        }

        /*
         * Marked, not deleted — the resident's history should still show that the
         * visit was booked and never happened.
         *
         * The status column is deliberately left as "Expected": the visitor never
         * arrived, so "Checked Out" would be a false record of a visit. `expired_at`
         * is the authoritative flag, and Visitor::scopeExpected() excludes it, so
         * no-shows drop out of the gate queue and the dashboard counts without
         * anything claiming they were admitted.
         */
        // The pass goes with the clearance, so the code on the visitor's phone
        // stops working at the same moment the clearance lapses.
        $stale->each(function (Visitor $visitor) {
            $pass = $visitor->gatePass;

            if ($pass?->canTransitionTo(PassStatus::Expired)) {
                $pass->transitionTo(PassStatus::Expired, reason: 'No-show: arrival grace period passed');
            }
        });

        $count = Visitor::noShow($hours)->update(['expired_at' => now()]);

        $this->info(sprintf('Expired %d visitor clearance(s) past the %d-hour grace period.', $count, $hours));

        return self::SUCCESS;
    }
}
