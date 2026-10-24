<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\AccessLogEntry;
use App\Models\DistressAlert;
use App\Models\GatePass;
use App\Models\User;
use App\Services\Safety\SilentAssistanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineGatePassSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_guard_can_sync_queued_offline_pin_scans(): void
    {
        $guard = User::factory()->create([
            'role' => UserRole::Security,
            'name' => 'Sergeant Miller',
        ]);

        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Bruce Wayne',
        ]);

        $pass = GatePass::create([
            'pass_id' => 'GP-TEST-1001',
            'user_id' => $homeowner->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => $homeowner->display_name ?? $homeowner->name,
            'property' => $homeowner->propertyLabel(),
            'access_zone' => PassCategory::Homeowner->defaultZone(),
            'designated_gate' => GateId::Any,
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 100,
            'uses_count' => 0,
            'metadata' => [],
        ]);

        $offlinePin = $pass->offline_pin;
        $this->assertNotEmpty($offlinePin);

        $historicalScanTime = now()->subHours(2)->startOfSecond();

        $response = $this->actingAs($guard)->postJson('/dashboard/gate-pass/sync-offline-scans', [
            'gate' => GateId::Gate01->value,
            'scans' => [
                [
                    'offline_id' => 'OFFLINE-BATCH-001',
                    'token_or_pin' => $offlinePin,
                    'method' => 'Offline Gate PIN',
                    'scanned_at' => $historicalScanTime->toDateTimeString(),
                    'action' => 'check_in',
                    'notes' => 'Offline gate scanner sync after router reboot',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'processed' => 1,
            'accepted' => 1,
            'rejected' => 0,
            'duplicates_skipped' => 0,
        ]);

        // Assert GatePass transitioned to CheckedIn
        $pass->refresh();
        $this->assertEquals(PassStatus::CheckedIn, $pass->status);

        // Assert AccessLogEntry created with preserved historical time
        $entry = AccessLogEntry::first();
        $this->assertNotNull($entry);
        $this->assertEquals('Bruce Wayne', $entry->user_name);
        $this->assertEquals('Offline Gate PIN', $entry->method);
        $this->assertEquals('PERMITTED', $entry->result);
        $this->assertEquals($guard->id, $entry->scanned_by);
        $this->assertEquals(
            $historicalScanTime->timestamp,
            $entry->occurred_at->timestamp
        );
    }

    public function test_duplicate_offline_scans_are_skipped_idempotently(): void
    {
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);

        $pass = GatePass::create([
            'pass_id' => 'GP-TEST-1002',
            'user_id' => $homeowner->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => $homeowner->display_name ?? $homeowner->name,
            'property' => $homeowner->propertyLabel(),
            'access_zone' => PassCategory::Homeowner->defaultZone(),
            'designated_gate' => GateId::Any,
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 10,
            'uses_count' => 0,
        ]);

        $payload = [
            'gate' => GateId::Gate01->value,
            'scans' => [
                [
                    'offline_id' => 'OFFLINE-DUP-TEST',
                    'token_or_pin' => $pass->offline_pin,
                    'action' => 'check_in',
                ],
            ],
        ];

        // First sync
        $response1 = $this->actingAs($guard)->postJson('/dashboard/gate-pass/sync-offline-scans', $payload);
        $response1->assertOk();
        $response1->assertJson(['processed' => 1, 'duplicates_skipped' => 0]);
        $this->assertEquals(1, AccessLogEntry::count());

        // Second sync (identical offline_id)
        $response2 = $this->actingAs($guard)->postJson('/dashboard/gate-pass/sync-offline-scans', $payload);
        $response2->assertOk();
        $response2->assertJson(['processed' => 0, 'duplicates_skipped' => 1]);
        $this->assertEquals(1, AccessLogEntry::count());
    }

    public function test_invalid_offline_scans_are_recorded_as_denied(): void
    {
        $guard = User::factory()->create(['role' => UserRole::Security]);

        $response = $this->actingAs($guard)->postJson('/dashboard/gate-pass/sync-offline-scans', [
            'gate' => GateId::Gate01->value,
            'scans' => [
                [
                    'offline_id' => 'OFFLINE-INVALID-001',
                    'token_or_pin' => '000000', // Non-existent PIN
                    'action' => 'check_in',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJson([
            'processed' => 1,
            'accepted' => 0,
            'rejected' => 1,
        ]);

        $entry = AccessLogEntry::first();
        $this->assertNotNull($entry);
        $this->assertEquals('DENY', $entry->result);
    }

    public function test_resident_cannot_sync_gate_scans(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);

        $response = $this->actingAs($homeowner)->postJson('/dashboard/gate-pass/sync-offline-scans', [
            'gate' => GateId::Gate01->value,
            'scans' => [
                ['offline_id' => 'OFFLINE-HACK-001', 'token_or_pin' => '123456'],
            ],
        ]);

        $response->assertForbidden();
    }

    private function residentPass(User $resident): GatePass
    {
        return GatePass::create([
            'pass_id' => 'GP-DURESS-'.$resident->id,
            'user_id' => $resident->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => $resident->name,
            'property' => 'Lot 3',
            'access_zone' => PassCategory::Homeowner->defaultZone(),
            'designated_gate' => GateId::Any,
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 100,
            'uses_count' => 0,
            'metadata' => [],
        ]);
    }

    /** A duress PIN keyed in while offline is admitted like the real PIN and raises the alert on sync. */
    public function test_a_duress_pin_synced_from_offline_raises_silent_assistance(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $pass = $this->residentPass($resident);
        app(SilentAssistanceService::class)->setDuressPin($resident, '271828');

        $this->actingAs($guard)->postJson('/dashboard/gate-pass/sync-offline-scans', [
            'gate' => GateId::Gate01->value,
            'scans' => [['offline_id' => 'OFF-DURESS-1', 'token_or_pin' => '271828', 'action' => 'check_in']],
        ])->assertOk()->assertJsonPath('accepted', 1);

        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);
        $this->assertTrue(DistressAlert::where('user_id', $resident->id)->where('channel', 'duress_pin')->exists());
    }

    public function test_a_scan_dated_in_the_future_is_refused(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->postJson('/dashboard/gate-pass/sync-offline-scans', [
                'scans' => [['offline_id' => 'OFF-1', 'token_or_pin' => '123456', 'scanned_at' => now()->addDay()->toIso8601String()]],
            ])
            ->assertJsonValidationErrors('scans.0.scanned_at');
    }

    public function test_a_batch_is_capped(): void
    {
        $scans = array_map(fn (int $i) => ['offline_id' => "OFF-{$i}", 'token_or_pin' => '123456'], range(1, 501));

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->postJson('/dashboard/gate-pass/sync-offline-scans', ['scans' => $scans])
            ->assertJsonValidationErrors('scans');
    }
}
