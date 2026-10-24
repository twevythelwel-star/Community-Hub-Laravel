<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Directory: renters, staff, visitors and the activity log.
 * Mirrors Renter, Staff, Visitor, ActivityLogEntry from src/types/index.ts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Lease records. The person is a `users` row; this is the tenancy.
        Schema::create('renters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('Active');   // Active | Inactive
            $table->date('lease_start');
            $table->date('lease_end');
            $table->string('lot')->nullable();
            $table->string('street')->nullable();
            $table->timestamps();

            $table->index(['status', 'lease_end']);
        });

        // Household / community staff. id_type: National ID, Passport, or licence.
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('job');
            $table->string('id_type');
            $table->string('id_number');
            $table->date('id_expiry');
            $table->string('id_image_url')->nullable();
            $table->string('property');         // e.g. "Lot 42, Main St"
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('Active'); // Active | Inactive | Expired ID
            $table->string('photo_url')->nullable();
            $table->timestamps();

            $table->index(['status', 'id_expiry']);
        });

        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');                 // One-time | Recurring
            $table->string('status')->default('Expected');
            $table->timestamp('expected_at');
            $table->string('date_range')->nullable();
            $table->foreignId('homeowner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('homeowner_name')->nullable(); // denormalised for display parity
            $table->string('id_image_url')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expected_at']);
        });

        Schema::create('activity_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log_entries');
        Schema::dropIfExists('visitors');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('renters');
    }
};
