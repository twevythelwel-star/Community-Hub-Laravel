<?php

namespace App\Console\Commands;

use App\Models\GateDevice;
use Illuminate\Console\Command;

/**
 * Registers a gate's hardware (scanner, turnstile controller, occupancy
 * sensor) and issues the key it authenticates with on /api/gate-devices.
 * Only a hash of the key is stored, so it is shown once; --rotate issues a
 * new one and the old key stops working at once.
 */
class RegisterGateDeviceCommand extends Command
{
    protected $signature = 'gate-devices:register
        {identifier : Unique device identifier, e.g. GATE-01-SENSOR-A}
        {--gate=GATE-01 : The gate the device stands at}
        {--name= : A readable name, shown on alerts}
        {--rotate : Issue a new key for an existing device}';

    protected $description = 'Register a gate device and issue its API key (shown once)';

    public function handle(): int
    {
        $identifier = (string) $this->argument('identifier');
        $gate = (string) $this->option('gate');
        $device = GateDevice::where('device_identifier', $identifier)->first();

        if (! array_key_exists($gate, config('gatepass.gates'))) {
            $this->error("Unknown gate {$gate}. Known gates: ".implode(', ', array_keys(config('gatepass.gates'))));

            return self::FAILURE;
        }

        if ($device && ! $this->option('rotate')) {
            $this->error("Device {$identifier} is already registered. Use --rotate to issue a new key.");

            return self::FAILURE;
        }

        $key = 'gd_'.bin2hex(random_bytes(32));

        if ($device) {
            $device->update(['device_key_hash' => GateDevice::hashKey($key), 'status' => 'active']);
            $this->info("New key issued for {$identifier}; the previous key no longer works.");
        } else {
            GateDevice::create([
                'device_identifier' => $identifier,
                'name' => $this->option('name') ?: $identifier,
                'gate_id' => $gate,
                'device_key_hash' => GateDevice::hashKey($key),
                'status' => 'active',
            ]);
            $this->info("Registered {$identifier} at {$gate}.");
        }

        $this->line('');
        $this->line('Device key (shown once; send it as the X-Gate-Device-Key header):');
        $this->line($key);

        return self::SUCCESS;
    }
}
