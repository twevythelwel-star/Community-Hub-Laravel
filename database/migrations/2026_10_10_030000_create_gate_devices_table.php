<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_identifier', 80)->unique();
            $table->string('name', 120);
            $table->string('gate_id', 40)->default('GATE-01')->index();
            $table->string('device_key_hash', 64)->unique();
            $table->string('status', 30)->default('active')->index(); // active, revoked, suspended
            $table->string('firmware_version', 50)->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_devices');
    }
};
