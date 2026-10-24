<?php

namespace Database\Seeders;

use App\Models\BoundaryConfig;
use App\Models\BrandingSetting;
use App\Models\Community;
use App\Services\GeofenceService;
use Illuminate\Database\Seeder;

/**
 * Community record, published boundary, landmarks and branding.
 *
 * Boundary coordinates come from DEFAULT_GEOFENCE_COORDS and landmarks from
 * DEFAULT_COMMUNITY_LANDMARKS in src/lib/geofence-utils.ts.
 */
class CommunitySeeder extends Seeder
{
    public function run(): void
    {
        $geofence = app(GeofenceService::class);

        $community = Community::updateOrCreate(
            ['code' => config('gatepass.default_community_id', 'CID-CYPRESS-BAY')],
            [
                'name' => 'Cypress Bay',
                'jurisdiction' => 'Saint James, Jamaica',
                'datum' => 'WGS84',
                'reference_lat' => 18.4766,
                'reference_lng' => -77.9257,
                'reference_dms' => $geofence->toDms(18.4766, true).' '.$geofence->toDms(-77.9257, false),
                'reference_description' => 'Montego Bay coastal ridge reference monument',
                'cadastral_zone' => 'ZONE-SJ-04',
            ],
        );

        // Eight-point estate perimeter.
        $coordinates = [
            [18.4742, -77.9284],
            [18.4748, -77.9241],
            [18.4771, -77.9232],
            [18.4790, -77.9246],
            [18.4793, -77.9271],
            [18.4779, -77.9291],
            [18.4760, -77.9296],
            [18.4748, -77.9293],
        ];

        $config = BoundaryConfig::updateOrCreate(
            ['community_id' => $community->id, 'version' => 1],
            [
                'status' => 'PUBLISHED',
                'published_coordinates' => $coordinates,
                'last_published_at' => now(),
                'last_published_by' => 'System Seed',
            ],
        );

        $config->points()->delete();

        foreach ($coordinates as $index => [$lat, $lng]) {
            $pointIndex = $index + 1;

            $config->points()->create([
                'point_index' => $pointIndex,
                'label' => "Point {$pointIndex}",
                'lat' => $lat,
                'lng' => $lng,
                'is_optional' => $pointIndex > 4,
            ]);
        }

        $metrics = $geofence->polygonMetrics($coordinates);

        $config->auditLogs()->firstOrCreate(
            ['action' => 'Boundary Published', 'new_version' => 1],
            [
                'community' => $community->name,
                'changed_by' => 'System Seed',
                'role' => 'System Admin',
                'previous_version' => 0,
                'points_count' => count($coordinates),
                'published' => true,
                'notes' => 'Initial boundary imported from DEFAULT_GEOFENCE_COORDS.',
                'area_acres' => $metrics['areaAcres'],
                'perimeter_meters' => $metrics['perimeterMeters'],
                'occurred_at' => now(),
            ],
        );

        // Landmarks: green pin = Security Gate, red = Community Center, blue = Park.
        $landmarks = [
            [
                'name' => 'Main Security Gate & Access Control',
                'category' => 'Security Gate',
                'description' => 'Primary vehicular entry with 24/7 security guardhouse, barrier arms, and RFID pass scanners.',
                'lat' => 18.4750,
                'lng' => -77.9257,
                'icon' => 'shield',
            ],
            [
                'name' => 'Community Center & Clubhouse',
                'category' => 'Community Center',
                'description' => 'Central community complex housing resident lounge, meeting room, and HOA management office.',
                'lat' => 18.4768,
                'lng' => -77.9250,
                'icon' => 'building',
            ],
            [
                'name' => 'North Perimeter Checkpoint',
                'category' => 'Security Gate',
                'description' => 'Secondary gate for service and delivery access with guard post.',
                'lat' => 18.4782,
                'lng' => -77.9258,
                'icon' => 'shield',
            ],
            [
                'name' => 'Central Recreation Park',
                'category' => 'Park',
                'description' => 'Landscaped recreational green space, outdoor seating, sports facilities, and children play zones.',
                'lat' => 18.4775,
                'lng' => -77.9268,
                'icon' => 'trees',
            ],
        ];

        foreach ($landmarks as $landmark) {
            $community->landmarks()->updateOrCreate(
                ['name' => $landmark['name']],
                $landmark,
            );
        }

        BrandingSetting::updateOrCreate(
            ['community_id' => $community->id],
            [
                'app_name' => 'Community Hub',
                'primary_color' => '#059669',
                'accent_color' => '#F97316',
                'background_color' => '#0F172A',
                'default_theme' => 'system',
            ],
        );
    }
}
