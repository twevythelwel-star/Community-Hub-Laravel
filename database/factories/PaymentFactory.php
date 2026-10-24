<?php

namespace Database\Factories;

use App\Enums\PaymentState;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'applies_to' => 'invoice',
            'purpose' => 'HOA Assessment',
            'channel' => 'bank_wire',
            'provider' => 'office',
            'state' => PaymentState::AwaitingTransfer,
            'user_id' => User::factory(),
            'amount_minor' => fake()->numberBetween(1_000_00, 20_000_00),
            'currency' => 'JMD',
        ];
    }

    /** A card payment through Stripe Checkout, just started. */
    public function stripe(): static
    {
        return $this->state(fn () => [
            'channel' => 'card',
            'provider' => 'stripe',
            'state' => PaymentState::Created,
            'provider_session_id' => 'cs_test_'.fake()->unique()->bothify('????????'),
        ]);
    }

    public function inState(PaymentState $state): static
    {
        return $this->state(fn () => ['state' => $state]);
    }
}
