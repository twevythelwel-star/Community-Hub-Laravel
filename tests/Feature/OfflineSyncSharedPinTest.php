<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\AccessLogEntry;
use App\Models\GatePass;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An offline PIN shared by two active passes cannot say who came through the
 * gate while it was offline. The sync records the entry for review and moves
 * neither pass, instead of checking in whichever it finds first.
 */
class OfflineSyncSharedPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_synced_pin_shared_by_two_passes_moves_neither(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));
        $guard = User::factory()->role(UserRole::Security)->create();
        [$first, $second] = $this->twoPassesSharingAPin();

        $this->actingAs($guard)->postJson('/dashboard/gate-pass/sync-offline-scans', [
            'gate' => GateId::Gate01->value,
            'scans' => [[
                'offline_id' => 'OFFLINE-SHARED-001',
                'token_or_pin' => $first->offline_pin,
                'method' => 'Offline Gate PIN',
                'scanned_at' => now()->subHour()->toDateTimeString(),
                'action' => 'check_in',
            ]],
        ])->assertOk()->assertJson(['processed' => 1, 'accepted' => 0, 'rejected' => 1]);

        $this->assertSame(PassStatus::Active, $first->fresh()->status);
        $this->assertSame(PassStatus::Active, $second->fresh()->status);

        $entry = AccessLogEntry::query()->latest('id')->first();
        $this->assertSame('OFFLINE-SHARED-PIN', $entry->pass_id);
        $this->assertStringContainsString('matches more than one pass', $entry->deny_reason);
        $this->assertNull($entry->user_id);
    }

    public function test_a_synced_pin_held_by_one_pass_still_checks_it_in(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();
        $pass = $this->activePass('GP-PIN-ONLY');

        $this->actingAs($guard)->postJson('/dashboard/gate-pass/sync-offline-scans', [
            'gate' => GateId::Gate01->value,
            'scans' => [[
                'offline_id' => 'OFFLINE-ONE-001',
                'token_or_pin' => $pass->offline_pin,
                'action' => 'check_in',
            ]],
        ])->assertOk()->assertJson(['accepted' => 1, 'rejected' => 0]);

        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);
    }

    /**
     * Two active passes whose derived PINs collide, found by trying pass IDs
     * in memory: about a thousand on average for a six-digit PIN.
     *
     * @return array{0: GatePass, 1: GatePass}
     */
    private function twoPassesSharingAPin(): array
    {
        $seen = [];
        for ($i = 0; $i < 20000; $i++) {
            $passId = sprintf('GP-PIN-%05d', $i);
            $pin = (new GatePass)->forceFill(['pass_id' => $passId, 'created_at' => now()])->offline_pin;

            if (isset($seen[$pin])) {
                return [$this->activePass($seen[$pin]), $this->activePass($passId)];
            }
            $seen[$pin] = $passId;
        }

        $this->fail('No PIN collision found among 20,000 pass IDs.');
    }

    private function activePass(string $passId): GatePass
    {
        $holder = User::factory()->role(UserRole::Homeowner)->create();

        return GatePass::create([
            'pass_id' => $passId,
            'user_id' => $holder->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => $holder->name,
            'status' => PassStatus::Active,
            'rotation_seq' => 0,
        ]);
    }
}
