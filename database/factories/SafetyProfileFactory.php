<?php

namespace Database\Factories;

use App\Models\SafetyProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SafetyProfile>
 */
class SafetyProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'silent_alerts_enabled' => true,
            'preferred_assistance' => fake()->randomElement(['police', 'medical', 'fire', 'security', 'contact']),
        ];
    }
}
