<?php

namespace Tests\Feature;

use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * routes/console.php runs queue:prune-batches every night, which needs the
 * job_batches table that Laravel's batch repository reads and writes.
 */
class JobBatchesTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_batches_table_has_the_columns_the_batch_repository_uses(): void
    {
        $this->assertTrue(Schema::hasColumns('job_batches', [
            'id',
            'name',
            'total_jobs',
            'pending_jobs',
            'failed_jobs',
            'failed_job_ids',
            'options',
            'cancelled_at',
            'created_at',
            'finished_at',
        ]));
    }

    public function test_batch_repository_can_store_and_find_a_batch(): void
    {
        $repository = $this->app->make(BatchRepository::class);

        $batch = $repository->store((new PendingBatch($this->app, collect()))->name('nightly-assessments'));

        $this->assertSame('nightly-assessments', $repository->find($batch->id)->name);
    }

    public function test_scheduled_prune_removes_only_expired_batches(): void
    {
        $now = now()->getTimestamp();
        $hour = 60 * 60;

        $oldFinished = $this->insertBatch(createdAt: $now - 50 * $hour, finishedAt: $now - 49 * $hour);
        $recentFinished = $this->insertBatch(createdAt: $now - 2 * $hour, finishedAt: $now - $hour);
        $oldUnfinished = $this->insertBatch(createdAt: $now - 73 * $hour, finishedAt: null);
        $recentUnfinished = $this->insertBatch(createdAt: $now - 10 * $hour, finishedAt: null);

        $this->artisan('queue:prune-batches', ['--hours' => 48, '--unfinished' => 72])->assertSuccessful();

        $this->assertDatabaseMissing('job_batches', ['id' => $oldFinished]);
        $this->assertDatabaseMissing('job_batches', ['id' => $oldUnfinished]);
        $this->assertDatabaseHas('job_batches', ['id' => $recentFinished]);
        $this->assertDatabaseHas('job_batches', ['id' => $recentUnfinished]);
    }

    public function test_prune_succeeds_when_there_are_no_batches(): void
    {
        $this->artisan('queue:prune-batches', ['--hours' => 48, '--unfinished' => 72])->assertSuccessful();

        $this->assertDatabaseCount('job_batches', 0);
    }

    private function insertBatch(int $createdAt, ?int $finishedAt): string
    {
        $id = (string) Str::orderedUuid();

        DB::table('job_batches')->insert([
            'id' => $id,
            'name' => 'batch-'.$id,
            'total_jobs' => 1,
            'pending_jobs' => $finishedAt === null ? 1 : 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => null,
            'cancelled_at' => null,
            'created_at' => $createdAt,
            'finished_at' => $finishedAt,
        ]);

        return $id;
    }
}
