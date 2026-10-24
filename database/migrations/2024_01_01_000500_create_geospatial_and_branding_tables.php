<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Community boundary / geofence and white-label branding.
 *
 * Replaces the localStorage persistence in src/lib/boundary-manager/service.ts,
 * src/lib/geofence-utils.ts, src/context/branding-context.tsx and
 * src/context/theme-context.tsx, so boundaries and branding are shared by every
 * user instead of living in one browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();          // e.g. CID-CYPRESS-BAY
            $table->string('jurisdiction')->nullable();
            $table->string('datum')->nullable();       // e.g. WGS84
            $table->decimal('reference_lat', 10, 7)->nullable();
            $table->decimal('reference_lng', 10, 7)->nullable();
            $table->string('reference_dms')->nullable();
            $table->string('reference_description')->nullable();
            $table->string('cadastral_zone')->nullable();
            $table->timestamps();
        });

        Schema::create('boundary_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('DRAFT');       // DRAFT | PUBLISHED
            $table->json('published_coordinates')->nullable(); // [[lat,lng], ...]
            $table->timestamp('last_published_at')->nullable();
            $table->string('last_published_by')->nullable();
            $table->timestamps();

            $table->index(['community_id', 'status']);
        });

        Schema::create('boundary_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boundary_config_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('point_index');   // 1..8
            $table->string('label');                      // e.g. "Point 1"
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->boolean('is_optional')->default(false); // false for 1..4, true for 5..8
            $table->timestamps();

            $table->unique(['boundary_config_id', 'point_index']);
        });

        Schema::create('boundary_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boundary_config_id')->constrained()->cascadeOnDelete();
            $table->string('community');
            $table->string('action');    // Boundary Published | Boundary Updated | Point Added | Point Removed | Draft Saved
            $table->string('changed_by');
            $table->string('role');
            $table->unsignedInteger('previous_version');
            $table->unsignedInteger('new_version');
            $table->unsignedInteger('points_count');
            $table->boolean('published')->default(false);
            $table->text('notes')->nullable();
            $table->string('area_acres')->nullable();
            $table->double('perimeter_meters')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('occurred_at');
        });

        Schema::create('landmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('icon')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // White-label branding: replaces BrandingProvider localStorage.
        Schema::create('branding_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('app_name')->default('Community Hub');
            $table->string('logo_url')->nullable();
            $table->string('primary_color')->nullable();
            $table->string('accent_color')->nullable();
            $table->string('background_color')->nullable();
            $table->string('default_theme')->default('system');  // light | dark | system
            $table->json('theme_tokens')->nullable();
            $table->timestamps();
        });

        // Per-user UI preference: replaces use-dark-mode / theme-context localStorage.
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('theme')->default('system');
            $table->boolean('map_show_boundary')->default(true);
            $table->boolean('map_show_landmarks')->default(true);
            $table->json('extra')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('branding_settings');
        Schema::dropIfExists('landmarks');
        Schema::dropIfExists('boundary_audit_logs');
        Schema::dropIfExists('boundary_points');
        Schema::dropIfExists('boundary_configs');
        Schema::dropIfExists('communities');
    }
};
