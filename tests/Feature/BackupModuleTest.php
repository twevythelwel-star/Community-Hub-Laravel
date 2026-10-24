<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Backup\BackupManagerService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_configuration_is_valid(): void
    {
        $this->assertEquals('Community Hub', config('backup.backup.name'));
        $this->assertEquals(['sqlite'], config('backup.backup.source.databases'));
        $this->assertNotEmpty(config('backup.backup.destination.disks'));
        $this->assertNotNull(config('backup.cleanup.default_strategy'));
        $this->assertNotEmpty(config('backup.monitor_backups'));
    }

    public function test_scheduled_backup_commands_are_registered_in_console(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $commands = $events->map(function ($event) {
            return (string) ($event->description ?: $event->command);
        });

        // Verify all 4 backup schedules are registered
        $hasDbBackup = $commands->contains(fn ($cmd) => str_contains($cmd, 'backup:daily-database') || str_contains($cmd, 'backup:run'));
        $hasFullBackup = $commands->contains(fn ($cmd) => str_contains($cmd, 'backup:weekly-full'));
        $hasCleanup = $commands->contains(fn ($cmd) => str_contains($cmd, 'backup:clean'));
        $hasMonitor = $commands->contains(fn ($cmd) => str_contains($cmd, 'backup:health-monitor') || str_contains($cmd, 'backup:monitor'));

        $this->assertTrue($hasDbBackup, 'backup:daily-database should be scheduled');
        $this->assertTrue($hasFullBackup, 'backup:weekly-full should be scheduled');
        $this->assertTrue($hasCleanup, 'backup:clean should be scheduled');
        $this->assertTrue($hasMonitor, 'backup:health-monitor should be scheduled');
    }

    public function test_backup_manager_service_returns_monitor_statuses(): void
    {
        Storage::fake('local');

        $service = app(BackupManagerService::class);
        $statuses = $service->getMonitorStatuses();

        $this->assertNotEmpty($statuses);
        $first = $statuses->first();

        $this->assertEquals('Community Hub', $first['name']);
        $this->assertEquals('local', $first['disk']);
        $this->assertIsBool($first['reachable']);
        $this->assertIsBool($first['healthy']);
        $this->assertIsInt($first['amount']);
    }

    public function test_artisan_backup_list_command_executes_successfully(): void
    {
        Storage::fake('local');

        $exitCode = Artisan::call('backup:list');

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Community Hub', $output);
        $this->assertStringContainsString('local', $output);
    }

    public function test_cloud_backup_destinations_are_properly_configured(): void
    {
        $disks = config('filesystems.disks');

        $this->assertArrayHasKey('local', $disks);
        $this->assertArrayHasKey('s3', $disks);
        $this->assertArrayHasKey('spaces', $disks);

        $this->assertEquals('s3', $disks['s3']['driver']);
        $this->assertEquals('s3', $disks['spaces']['driver']);
    }
}
