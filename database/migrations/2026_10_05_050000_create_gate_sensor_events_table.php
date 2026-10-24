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
        Schema::create('gate_sensor_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_pass_id')->nullable()->constrained('gate_passes')->nullOnDelete();
            $table->string('pass_id', 64)->nullable()->index();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('license_plate', 32)->nullable()->index();
            $table->string('gate', 32)->default('GATE-01');
            $table->string('direction', 16)->default('in');
            $table->string('sequence_state', 32)->default('COMPLETED');
            $table->unsignedSmallInteger('authorized_occupants')->default(1);
            $table->unsignedSmallInteger('detected_occupants')->default(1);
            $table->boolean('is_tailgating')->default(false)->index();
            $table->string('severity', 16)->default('INFO');
            $table->string('alert_title')->nullable();
            $table->text('alert_message')->nullable();
            $table->json('sensor_metadata')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_status', 32)->default('CLEAR');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['gate', 'is_tailgating', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gate_sensor_events');
    }
};
