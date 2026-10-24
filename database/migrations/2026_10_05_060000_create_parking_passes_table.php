<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('parking_passes', function (Blueprint $table) {
            $table->id();
            $table->string('pass_id', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('category', 32); // resident, visitor, contractor, temporary, accessible, loading_zone
            $table->string('license_plate', 32)->index();
            $table->string('property', 64)->default('Unit 14');
            $table->string('assigned_bay', 64)->nullable();
            $table->string('holder_name', 128);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->unsignedInteger('max_duration_minutes')->nullable();
            $table->string('status', 32)->default('active'); // active, checked_in, expired, revoked
            $table->text('qr_payload')->nullable();
            $table->json('metadata')->nullable(); // placard_number, ev_charging_enabled, permit_notes
            $table->timestamps();

            $table->index(['category', 'status']);
            $table->index(['property', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parking_passes');
    }
};
