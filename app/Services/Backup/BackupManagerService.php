<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Config\MonitoredBackupsConfig;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;

class BackupManagerService
{
    /**
     * Run a database-only backup to the configured backup disks.
     *
     * @return array{exit_code: int, output: string}
     */
    public function runDatabaseBackup(?string $disk = null): array
    {
        $params = ['--only-db' => true];

        if ($disk) {
            $params['--only-to-disk'] = $disk;
        }

        $exitCode = Artisan::call('backup:run', $params);

        return [
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ];
    }

    /**
     * Run a full application backup (database + files) to the configured backup disks.
     *
     * @return array{exit_code: int, output: string}
     */
    public function runFullBackup(?string $disk = null): array
    {
        $params = [];

        if ($disk) {
            $params['--only-to-disk'] = $disk;
        }

        $exitCode = Artisan::call('backup:run', $params);

        return [
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ];
    }

    /**
     * Clean up expired backups according to configured retention strategy.
     *
     * @return array{exit_code: int, output: string}
     */
    public function cleanExpiredBackups(): array
    {
        $exitCode = Artisan::call('backup:clean');

        return [
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ];
    }

    /**
     * Get health and monitoring statuses of configured backup destinations.
     *
     * @return Collection<int, array{
     *     name: string,
     *     disk: string,
     *     reachable: bool,
     *     healthy: bool,
     *     amount: int,
     *     newest: ?string,
     *     used_storage: string,
     * }>
     */
    public function getMonitorStatuses(): Collection
    {
        $config = MonitoredBackupsConfig::fromArray(config('backup.monitor_backups'));
        $statuses = BackupDestinationStatusFactory::createForMonitorConfig($config);

        return collect($statuses)->map(function (BackupDestinationStatus $status) {
            $destination = $status->backupDestination();

            return [
                'name' => $destination->backupName(),
                'disk' => $destination->diskName(),
                'reachable' => $destination->isReachable(),
                'healthy' => $status->isHealthy(),
                'amount' => $destination->backups()->count(),
                'newest' => $destination->newestBackup() ? $destination->newestBackup()->date()->toDateTimeString() : null,
                'used_storage' => $destination->usedStorage() > 0
                    ? round($destination->usedStorage() / 1024 / 1024, 2).' MB'
                    : '0 MB',
            ];
        });
    }

    /**
     * Get list of all backup archives on a given disk.
     *
     * @return Collection<int, array{
     *     path: string,
     *     date: string,
     *     size: int,
     *     human_size: string,
     *     disk: string,
     * }>
     */
    public function getBackupList(string $disk = 'local'): Collection
    {
        $destination = BackupDestination::create($disk, config('backup.backup.name'));

        return $destination->backups()->map(function (Backup $backup) use ($disk) {
            $bytes = (float) $backup->sizeInBytes();
            $units = ['B', 'KB', 'MB', 'GB'];
            $i = 0;
            while ($bytes >= 1024 && $i < count($units) - 1) {
                $bytes /= 1024;
                $i++;
            }

            return [
                'path' => $backup->path(),
                'date' => $backup->date()->toDateTimeString(),
                'size' => $backup->sizeInBytes(),
                'human_size' => round($bytes, 2).' '.$units[$i],
                'disk' => $disk,
            ];
        });
    }
}
