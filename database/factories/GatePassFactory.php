<?php

namespace Database\Factories;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Models\GatePass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GatePass>
 */
class GatePassFactory extends Factory
{
    protected $model = GatePass::class;

    public function definition(): array
    {
        return [
            'pass_id' => 'PASS-'.strtoupper(Str::random(8)),
            'user_id' => User::factory(),
            'visitor_id' => null,
            'category' => PassCategory::Homeowner,
            'holder_name' => fake()->name(),
            'property' => 'Lot 101, Palm Avenue',
            'access_zone' => 'Full Access',
            'designated_gate' => GateId::Gate01,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addYear(),
            'single_entry' => false,
            'color_variant' => 'emerald',
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
        ];
    }
}
