<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    public function definition(): array
    {
        $user = User::factory()->create();

        return [
            'name' => fake()->name(),
            'contact' => fake()->email(),
            'vehicle' => fake()->optional()->sentence(),
            'id_type' => fake()->optional()->randomElement(['National ID', 'Passport', 'Driver\'s License']),
            'id_number' => fake()->optional()->numerify('############'),
            'type' => fake()->randomElement(['One-time', 'Recurring']),
            'status' => 'Expected',
            'expected_at' => fake()->dateTimeBetween('+1 hour', '+1 week'),
            'date_range' => fake()->optional()->date('Y-m-d').' - '.fake()->optional()->date('Y-m-d'),
            'homeowner_id' => $user->id,
            'homeowner_name' => $user->display_name,
            'id_image_url' => fake()->optional()->imageUrl(),
            'is_blocked' => false,
            'notify_email' => true,
            'notify_sms' => false,
            'notify_whatsapp' => false,
        ];
    }
}
