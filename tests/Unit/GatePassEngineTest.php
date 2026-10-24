<?php

namespace Tests\Unit;

use App\Enums\DenyReason;
use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\ValidationStatus;
use App\Models\GatePass;
use App\Models\User;
use App\Services\GatePassEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the four validation stages and the behaviours the JavaScript engine
 * could not enforce: a secret the client cannot read, a signature that cannot be
 * forged, and replay detection that survives a reload.
 */
class GatePassEngineTest extends TestCase
{
    use RefreshDatabase;

    private GatePassEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('gatepass.secret', 'base64:'.base64_encode(str_repeat('k', 48)));
        config()->set('gatepass.window_seconds', 30);

        $this->engine = new GatePassEngine;
    }

    private function makePass(PassCategory $category = PassCategory::Homeowner): GatePass
    {
        $user = User::factory()->create([
            'role' => $category === PassCategory::Staff ? 'Staff' : 'Homeowner',
            'lot' => 'Lot 42',
            'street' => 'Royal Palm Drive',
        ]);

        return GatePass::create([
            'pass_id' => $this->engine->formatPassId($category, '42'),
            'user_id' => $user->id,
            'category' => $category,
            'holder_name' => $user->display_name,
            'property' => $user->propertyLabel(),
            'access_zone' => $category->defaultZone(),
            'designated_gate' => GateId::Any,
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
        ]);
    }

    // ── Stage 1: structure ───────────────────────────────────────────

    public function test_it_rejects_a_qr_string_it_did_not_issue(): void
    {
        $report = $this->engine->validate('https://example.com/not-a-pass');

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::NotAGpeGatePass->value, $report['primaryReason']);
        $this->assertFalse($report['stages']['structure']['passed']);
    }

    public function test_it_rejects_a_token_with_the_wrong_segment_count(): void
    {
        $report = $this->engine->validate('CH-GPE:v1.onlyonesegment');

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::InvalidQrStructure->value, $report['primaryReason']);
    }

    public function test_it_rejects_a_pass_issued_for_another_community(): void
    {
        $pass = $this->makePass();
        $token = $this->engine->issueToken($pass)['token'];

        // Same token, different estate.
        config()->set('gatepass.default_community_id', 'CID-SOMEWHERE-ELSE');

        $report = (new GatePassEngine)->validate($token);

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::UnauthorizedCommunity->value, $report['primaryReason']);
    }

    // ── Stage 2: signature ───────────────────────────────────────────

    public function test_a_valid_token_passes_every_stage(): void
    {
        $pass = $this->makePass();
        $token = $this->engine->issueToken($pass)['token'];
        $report = $this->engine->validate($token, GateId::Gate01);

        $this->assertSame(ValidationStatus::Allow->value, $report['status'], $report['primaryReason']);

        foreach (['structure', 'cryptography', 'serverCache', 'accessPolicy'] as $stage) {
            $this->assertTrue($report['stages'][$stage]['passed'], "Stage {$stage} did not pass.");
        }

        foreach ($report['checks'] as $check => $passed) {
            $this->assertTrue($passed, "Check {$check} did not pass.");
        }
    }

    public function test_it_rejects_a_token_whose_payload_was_tampered_with(): void
    {
        $pass = $this->makePass();
        $issued = $this->engine->issueToken($pass);

        // Escalate the category in the payload but keep the original signature —
        // exactly the attack the old 32-bit hash could not withstand.
        $payload = $issued['payload'];
        $payload['cat'] = PassCategory::SysAdmin->value;

        $encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $forged = 'CH-GPE:v1.'.$encoded.'.'.$payload['sig'];

        $report = $this->engine->validate($forged);

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::SignatureMismatch->value, $report['primaryReason']);
        $this->assertFalse($report['stages']['cryptography']['passed']);
    }

    public function test_a_token_signed_with_a_different_secret_is_rejected(): void
    {
        $pass = $this->makePass();
        $token = (new GatePassEngine('base64:'.base64_encode(str_repeat('x', 48))))
            ->issueToken($pass)['token'];

        $report = $this->engine->validate($token);

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::SignatureMismatch->value, $report['primaryReason']);
    }

    public function test_the_signature_is_independent_of_payload_key_order(): void
    {
        $pass = $this->makePass();
        $issued = $this->engine->issueToken($pass);

        $shuffled = $issued['payload'];
        $signature = $shuffled['sig'];
        unset($shuffled['sig']);

        // Reverse the key order, then re-attach the original signature.
        $shuffled = array_reverse($shuffled, true);
        $shuffled['sig'] = $signature;

        $encoded = rtrim(strtr(base64_encode(json_encode($shuffled)), '+/', '-_'), '=');

        $report = $this->engine->validate('CH-GPE:v1.'.$encoded.'.'.$signature);

        $this->assertSame(ValidationStatus::Allow->value, $report['status'], $report['primaryReason']);
    }

    // ── Stage 3: time, revocation, replay ────────────────────────────

    public function test_it_rejects_an_expired_token(): void
    {
        $pass = $this->makePass();
        $issued = $this->engine->issueToken($pass);

        $report = $this->engine->validate(
            $issued['token'],
            GateId::Gate01,
            $issued['valid_until']->addSeconds(31),
        );

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::TokenExpired->value, $report['primaryReason']);
    }

    public function test_it_rejects_a_token_from_the_future_beyond_clock_skew(): void
    {
        $pass = $this->makePass();
        $issued = $this->engine->issueToken($pass);

        $report = $this->engine->validate(
            $issued['token'],
            GateId::Gate01,
            $issued['valid_from']->subSeconds(60),
        );

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::TokenNotYetValid->value, $report['primaryReason']);
    }

    public function test_it_rejects_a_revoked_pass(): void
    {
        $pass = $this->makePass();
        $token = $this->engine->issueToken($pass)['token'];

        $pass->revoke(null, 'Lost device');

        $report = $this->engine->validate($token);

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::PassRevoked->value, $report['primaryReason']);
    }

    public function test_it_rejects_a_pass_held_by_a_deactivated_account(): void
    {
        $pass = $this->makePass();
        $token = $this->engine->issueToken($pass)['token'];

        $pass->user->update(['status' => 'Inactive', 'deactivated_at' => now()]);

        $report = $this->engine->validate($token);

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::UserNotActive->value, $report['primaryReason']);
    }

    public function test_a_replayed_token_is_rejected_on_the_second_scan(): void
    {
        $pass = $this->makePass();
        $token = $this->engine->issueToken($pass)['token'];

        $first = $this->engine->validate($token, GateId::Gate01);
        $this->assertSame(ValidationStatus::Allow->value, $first['status']);

        // A screenshot of the same token, scanned again inside the same window.
        $second = $this->engine->validate($token, GateId::Gate01);

        $this->assertSame(ValidationStatus::Deny->value, $second['status']);
        $this->assertStringContainsString(DenyReason::ReplayAttack->value, $second['primaryReason']);
    }

    public function test_replay_detection_survives_a_fresh_engine_instance(): void
    {
        $pass = $this->makePass();
        $token = $this->engine->issueToken($pass)['token'];

        $this->engine->validate($token, GateId::Gate01);

        // A new instance stands in for a page reload or a second guard device —
        // the situation in which the old in-memory nonce cache forgot everything.
        $report = (new GatePassEngine)->validate($token, GateId::Gate01);

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::ReplayAttack->value, $report['primaryReason']);
    }

    // ── Stage 4: access policy ───────────────────────────────────────

    public function test_a_gate_restricted_pass_is_refused_at_another_gate(): void
    {
        $pass = $this->makePass();
        $issued = $this->engine->issueToken($pass, GateId::Gate02);

        $report = $this->engine->validate($issued['token'], GateId::Gate01);

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::UnauthorizedGate->value, $report['primaryReason']);
    }

    public function test_staff_are_refused_outside_their_shift_hours(): void
    {
        $pass = $this->makePass(PassCategory::Staff);
        $issued = $this->engine->issueToken($pass);

        // Staff hours are 06:00-19:00, Mon-Sat. 04:00 on a Tuesday is outside.
        $tuesdayPreDawn = CarbonImmutable::createFromTimestamp($issued['payload']['vf'])
            ->next('Tuesday')
            ->setTime(4, 0);

        // Re-issue in that window so the token itself is temporally valid.
        CarbonImmutable::setTestNow($tuesdayPreDawn);
        $token = $this->engine->issueToken($pass)['token'];

        $report = $this->engine->validate($token, GateId::Gate01, $tuesdayPreDawn);
        CarbonImmutable::setTestNow();

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::OutsideHours->value, $report['primaryReason']);
    }

    public function test_staff_are_refused_on_a_non_operating_day(): void
    {
        $pass = $this->makePass(PassCategory::Staff);

        // Staff days are Mon-Sat, so Sunday midday is a denial.
        $sundayNoon = CarbonImmutable::now()->next('Sunday')->setTime(12, 0);

        CarbonImmutable::setTestNow($sundayNoon);
        $token = $this->engine->issueToken($pass)['token'];
        $report = $this->engine->validate($token, GateId::Gate01, $sundayNoon);
        CarbonImmutable::setTestNow();

        $this->assertSame(ValidationStatus::Deny->value, $report['status']);
        $this->assertStringContainsString(DenyReason::OutsideHours->value, $report['primaryReason']);
    }

    // ── Visual identity ─────────────────────────────────────────────

    public function test_colour_assignment_is_deterministic_for_a_given_seed(): void
    {
        $first = $this->engine->assignedColorVariant(PassCategory::Homeowner, 'GP-HO-0042', 1);
        $second = $this->engine->assignedColorVariant(PassCategory::Homeowner, 'GP-HO-0042', 1);

        $this->assertSame($first['id'], $second['id']);
    }

    public function test_rotation_advances_the_sequence_and_the_variant(): void
    {
        $pass = $this->makePass();

        $before = $this->engine->assignedColorVariant($pass->category, $pass->pass_id, $pass->rotation_seq);
        $result = $this->engine->rotateVisualIdentity($pass->fresh());

        $this->assertSame(2, $result['next_seq']);
        $this->assertSame(2, $pass->fresh()->rotation_seq);
        $this->assertNotSame($before['id'], $result['variant']['id']);
    }

    public function test_every_palette_entry_meets_the_wcag_aa_contrast_floor(): void
    {
        foreach (config('gatepass.palettes') as $category => $palette) {
            foreach ($palette as $variant) {
                $this->assertGreaterThanOrEqual(
                    4.5,
                    $variant['contrast_ratio'],
                    "{$category}/{$variant['id']} is below the 4.5:1 floor.",
                );
                $this->assertTrue($variant['wcag_pass']);
            }
        }
    }

    public function test_each_category_maps_to_its_own_distinct_shape(): void
    {
        $shapes = array_map(
            fn (PassCategory $c) => $c->shape()->value,
            PassCategory::cases(),
        );

        $this->assertCount(count($shapes), array_unique($shapes), 'Two categories share a shape.');
    }

    public function test_pass_ids_are_prefixed_per_category(): void
    {
        $this->assertSame('GP-SYS-0001', $this->engine->formatPassId(PassCategory::SysAdmin, '1'));
        $this->assertSame('GP-HO-0042', $this->engine->formatPassId(PassCategory::Homeowner, 'user-42'));
        $this->assertSame('GP-SEC-0007', $this->engine->formatPassId(PassCategory::Security, '7'));
    }

    public function test_the_engine_refuses_to_start_without_a_secret(): void
    {
        config()->set('gatepass.secret', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GATE_ENGINE_SECRET/');

        new GatePassEngine;
    }
}
