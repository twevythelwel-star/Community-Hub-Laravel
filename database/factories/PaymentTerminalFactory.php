<?php

namespace Database\Factories;

use App\Models\PaymentTerminal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTerminal>
 */
class PaymentTerminalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'stripe_terminal',
            'terminal_id' => 'tmr_'.fake()->unique()->bothify('????????????'),
            'device_id' => 'WSC'.fake()->unique()->numerify('#########'),
            'device_type' => 'bbpos_wisepos_e',
            'location_id' => 'tml_'.fake()->bothify('????????????'),
            'country' => 'US',
            'label' => 'Office reader',
            'status' => 'active',
            'verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['verified_at' => null]);
    }

    public function retired(): static
    {
        return $this->state(fn () => ['status' => 'retired']);
    }
}
