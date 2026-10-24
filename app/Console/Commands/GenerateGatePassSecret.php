<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generates and writes GATE_ENGINE_SECRET.
 *
 * The signing key must be high-entropy and server-only. Rotating it invalidates
 * every pass token in flight, which is by design: a compromised key must be
 * replaceable without redeploying the client.
 */
class GenerateGatePassSecret extends Command
{
    protected $signature = 'gatepass:secret {--show : Print the key instead of writing it to .env}
                                            {--force : Overwrite an existing key}';

    protected $description = 'Generate the Digital Gate Pass Engine signing secret';

    public function handle(): int
    {
        $key = 'base64:'.base64_encode(random_bytes(48));

        if ($this->option('show')) {
            $this->line($key);

            return self::SUCCESS;
        }

        $path = base_path('.env');

        if (! file_exists($path)) {
            $this->error('.env not found. Copy .env.example to .env first.');

            return self::FAILURE;
        }

        $contents = file_get_contents($path);
        $existing = preg_match('/^GATE_ENGINE_SECRET=(.*)$/m', $contents, $matches)
            ? trim($matches[1])
            : '';

        if ($existing !== '' && ! $this->option('force')) {
            $this->warn('GATE_ENGINE_SECRET is already set.');
            $this->line('Rotating it will invalidate every gate pass token currently in circulation.');

            if (! $this->confirm('Rotate it anyway?', false)) {
                return self::SUCCESS;
            }
        }

        $contents = preg_match('/^GATE_ENGINE_SECRET=.*$/m', $contents)
            ? preg_replace('/^GATE_ENGINE_SECRET=.*$/m', 'GATE_ENGINE_SECRET='.$key, $contents)
            : rtrim($contents, "\n")."\nGATE_ENGINE_SECRET=".$key."\n";

        file_put_contents($path, $contents);

        $this->info('Gate pass signing secret written to .env');
        $this->line('Run `php artisan config:clear` if you have cached config.');

        return self::SUCCESS;
    }
}
