<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PrivacyDataRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_expired_visitor_identifications_older_than_30_days_per_jdpa_2020(): void
    {
        $homeowner = User::factory()->create(['lot' => '12']);
        $property = Property::create([
            'property_code' => 'UNIT-12',
            'lot_number' => '12',
            'user_id' => $homeowner->id,
        ]);

        // Visitor 1: Checked out 45 days ago (should be pruned)
        $oldVisitor = Visitor::create([
            'homeowner_id' => $homeowner->id,
            'property_id' => $property->id,
            'name' => 'Expired Guest',
            'type' => 'Guest',
            'id_type' => 'Driver License',
            'id_number' => 'DL-98214478',
            'contact' => '876-555-1234',
            'status' => 'Checked Out',
            'checked_out_at' => now()->subDays(45),
            'expected_at' => now()->subDays(46),
        ]);

        // Visitor 2: Active today (must NOT be pruned)
        $activeVisitor = Visitor::create([
            'homeowner_id' => $homeowner->id,
            'property_id' => $property->id,
            'name' => 'Current Active Guest',
            'type' => 'Guest',
            'id_type' => 'National ID',
            'id_number' => 'NID-11223344',
            'contact' => '876-555-9876',
            'status' => 'Checked In',
            'expected_at' => now(),
        ]);

        // Run the JDPA 2020 privacy retention command
        $exitCode = Artisan::call('privacy:prune-identifications', ['--days' => 30]);
        $this->assertSame(0, $exitCode);

        // Verify old visitor identification is nullified
        $oldVisitor->refresh();
        $this->assertNull($oldVisitor->id_number);
        $this->assertNull($oldVisitor->contact);
        $this->assertSame('Expired Guest', $oldVisitor->name); // Retains anonymized audit name/date

        // Verify active visitor identification is untouched
        $activeVisitor->refresh();
        $this->assertSame('NID-11223344', $activeVisitor->id_number);
        $this->assertSame('876-555-9876', $activeVisitor->contact);
    }
}
