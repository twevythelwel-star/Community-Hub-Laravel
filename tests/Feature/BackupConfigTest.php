<?php

namespace Tests\Feature;

use Spatie\Backup\Tasks\Backup\FileSelection;
use Tests\TestCase;

/**
 * What the scheduled backups (routes/console.php) would put in an archive,
 * and where. An archive that leaves the server must not carry the app's
 * secrets, and production must be able to name an off-site disk.
 *
 * Rather than walk the whole project (a minute per run), each check asks
 * Spatie's own FileSelection::shouldExclude() — the decision the full walk
 * applies to every file under base_path() — about one path.
 */
class BackupConfigTest extends TestCase
{
    /** FileSelection built from the real exclude list, exposing its exclusion decision. */
    private function selection(): object
    {
        $files = config('backup.backup.source.files');

        $selection = new class($files['include']) extends FileSelection
        {
            public function excludes(string $path): bool
            {
                return $this->shouldExclude($path);
            }
        };

        return $selection->excludeFilesFrom($files['exclude']);
    }

    /** @return list<string> */
    private function configuredExcludes(): array
    {
        return array_map(fn (string $p) => str_replace('\\', '/', $p), config('backup.backup.source.files.exclude'));
    }

    public function test_the_live_env_file_is_excluded(): void
    {
        // Present wherever the app runs (CI copies .env.example to .env).
        $this->assertFileExists(base_path('.env'));
        $this->assertTrue($this->selection()->excludes(base_path('.env')));
    }

    public function test_logs_and_rebuildable_directories_are_excluded(): void
    {
        $selection = $this->selection();

        // storage/logs/.gitignore is committed, so this always exists.
        $this->assertTrue($selection->excludes(storage_path('logs/.gitignore')), 'storage/logs must not be archived');
        $this->assertTrue($selection->excludes(base_path('vendor/autoload.php')), 'vendor must not be archived');

        foreach (['node_modules', 'public/build'] as $directory) {
            if (is_dir(base_path($directory))) {
                $this->assertTrue($selection->excludes(base_path($directory)), "{$directory} must not be archived");
            }
        }
    }

    public function test_secrets_that_may_not_exist_here_are_still_listed(): void
    {
        // Spatie drops exclude entries that do not exist, so these cannot be
        // probed on a machine without them; the list itself is what must hold.
        $excludes = $this->configuredExcludes();

        foreach (['.env', '.env.backup', '.env.production', 'auth.json'] as $secret) {
            $this->assertContains(str_replace('\\', '/', base_path($secret)), $excludes, "{$secret} must be excluded");
        }

        foreach (['oauth-private.key', 'oauth-public.key'] as $key) {
            $this->assertContains(str_replace('\\', '/', storage_path($key)), $excludes, "{$key} must be excluded");
        }
    }

    public function test_the_application_itself_is_still_archived(): void
    {
        $selection = $this->selection();

        $this->assertSame([base_path()], config('backup.backup.source.files.include'));
        $this->assertFalse($selection->excludes(base_path('composer.json')));
        $this->assertFalse($selection->excludes(base_path('config/backup.php')));
        $this->assertFalse($selection->excludes(base_path('.env.example')), 'only the real .env is a secret');
    }

    public function test_backup_disks_and_the_monitor_follow_backup_disks(): void
    {
        // Laravel reads $_SERVER and $_ENV before getenv(), and .env (copied
        // from .env.example in CI) already sets BACKUP_DISKS — so all three
        // must be overridden, and restored.
        $saved = [$_SERVER['BACKUP_DISKS'] ?? null, $_ENV['BACKUP_DISKS'] ?? null, getenv('BACKUP_DISKS')];
        $_SERVER['BACKUP_DISKS'] = $_ENV['BACKUP_DISKS'] = ' s3, local ,';
        putenv('BACKUP_DISKS= s3, local ,');

        try {
            $config = require config_path('backup.php');
        } finally {
            [$server, $env, $getenv] = $saved;
            if ($server === null) {
                unset($_SERVER['BACKUP_DISKS']);
            } else {
                $_SERVER['BACKUP_DISKS'] = $server;
            }
            if ($env === null) {
                unset($_ENV['BACKUP_DISKS']);
            } else {
                $_ENV['BACKUP_DISKS'] = $env;
            }
            putenv($getenv === false ? 'BACKUP_DISKS' : "BACKUP_DISKS={$getenv}");
        }

        $this->assertSame(['s3', 'local'], $config['backup']['destination']['disks']);
        $this->assertSame(['s3', 'local'], $config['monitor_backups'][0]['disks']);
    }

    public function test_backups_default_to_local_and_alert_a_real_address(): void
    {
        $this->assertSame(['local'], config('backup.backup.destination.disks'));
        $this->assertNotSame('your@example.com', config('backup.notifications.mail.to'));
        $this->assertSame(config('mail.from.address'), config('backup.notifications.mail.to'));
        $this->assertTrue(config('backup.backup.verify_backup'));
    }
}
