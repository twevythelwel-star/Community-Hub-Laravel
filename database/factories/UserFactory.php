<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'uid' => (string) Str::uuid(),
            'name' => $name,
            'display_name' => $name,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('(876) 555-####'),
            'role' => UserRole::Homeowner->value,
            'title' => 'Verified Homeowner',
            'lot' => 'Lot '.fake()->numberBetween(1, 99),
            'street' => fake()->streetName(),
            'status' => 'Active',
            'email_verified_at' => now(),
            'password' => static::$password ??= bcrypt('password'),
            'ai_consent' => false,
        ];
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn () => ['role' => $role->value]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => 'Inactive',
            'deactivated_at' => now(),
        ]);
    }
}
