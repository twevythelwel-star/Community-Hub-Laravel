<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\GatePass;
use App\Models\User;
use App\Services\DigitalAccessWalletService;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The offline PIN is six digits derived from the pass, so two active passes
 * can share one. A shared PIN names no one, so the gate refuses it rather
 * than checking in whichever pass it finds first.
 */
class OfflinePinTest extends TestCase
{
    use RefreshDatabase;

    private User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));
        $this->guard = User::factory()->role(UserRole::Security)->create();
    }

    public function test_a_pin_held_by_one_pass_checks_that_pass_in(): void
    {
        $pass = app(GatePassEngine::class)->issuePassFor(User::factory()->role(UserRole::Homeowner)->create());

        $result = app(GateScanner::class)->scan($pass->offline_pin, GateId::Gate01, $this->guard);

        $this->assertSame('CHECK_IN', $result['decision']);
        $this->assertSame($pass->pass_id, $result['report']['passId']);
    }

    public function test_a_pin_shared_by_two_passes_is_refused(): void
    {
        [$first, $second] = $this->twoPassesSharingAPin();
        $this->assertSame($first->offline_pin, $second->offline_pin);

        $result = app(GateScanner::class)->scan($first->offline_pin, GateId::Gate01, $this->guard);

        $this->assertSame('REJECT', $result['decision']);
        $this->assertNull($result['scanId']);
        $this->assertStringContainsString('matches more than one pass', $result['report']['primaryReason']);
        $this->assertSame(PassStatus::Active, $first->fresh()->status);
        $this->assertSame(PassStatus::Active, $second->fresh()->status);
    }

    public function test_the_wallet_shows_the_passes_own_pin(): void
    {
        $pass = app(GatePassEngine::class)->issuePassFor(User::factory()->role(UserRole::Homeowner)->create());

        $credential = app(DigitalAccessWalletService::class)->formatWalletPass($pass, 'Homeowner');

        $this->assertSame($pass->offline_pin, $credential['offline_pin']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $credential['offline_pin']);
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
