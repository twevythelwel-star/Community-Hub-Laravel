<?php

namespace App\Console\Commands;

use App\Models\GateDevice;
use Illuminate\Console\Command;

/** Cuts a gate device off: its key stops working at once. */
class RevokeGateDeviceCommand extends Command
{
    protected $signature = 'gate-devices:revoke {identifier : The device identifier}';

    protected $description = 'Revoke a gate device so its API key no longer works';

    public function handle(): int
    {
        $device = GateDevice::where('device_identifier', $this->argument('identifier'))->first();

        if (! $device) {
            $this->error('No device with that identifier.');

            return self::FAILURE;
        }

        $device->update(['status' => 'revoked']);
        $this->info("Revoked {$device->device_identifier}.");

        return self::SUCCESS;
    }
}
