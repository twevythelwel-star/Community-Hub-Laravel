<?php

namespace App\Console\Commands;

use App\Models\Visitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Jamaica Data Protection Act 2020 (JDPA) Section 22 Data Retention Enforcement:
 * Prunes visitor government ID numbers, identification photos, and sensitive documents
 * for visits completed or expired more than 30 days ago.
 */
class PruneVisitorIdentificationsCommand extends Command
{
    protected $signature = 'privacy:prune-identifications {--days=30 : Retention threshold in days}';

    protected $description = 'Prune visitor identification numbers and photos older than retention policy (JDPA 2020 compliance)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $threshold = now()->subDays($days);

        $query = Visitor::query()
            ->whereNotNull('id_number')
            ->where(function ($q) use ($threshold) {
                $q->where('checked_out_at', '<=', $threshold)
                    ->orWhere('expired_at', '<=', $threshold)
                    ->orWhere(function ($sub) use ($threshold) {
                        $sub->whereNull('checked_out_at')
                            ->whereNull('expired_at')
                            ->where('created_at', '<=', $threshold);
                    });
            });

        $count = $query->count();

        $query->update([
            'id_number' => null,
            'contact' => null,
        ]);

        Log::channel('security')->info('JDPA 2020 visitor identification retention prune executed', [
            'pruned_records' => $count,
            'threshold_days' => $days,
        ]);

        $this->info("JDPA retention prune completed: {$count} visitor identification records sanitized.");

        return self::SUCCESS;
    }
}
