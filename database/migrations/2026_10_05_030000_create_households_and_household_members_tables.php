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
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('primary_homeowner_id')->constrained('users')->cascadeOnDelete();
            $table->string('property_number', 50)->index();
            $table->string('name', 120);
            $table->string('address', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('household_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('role_in_household', 50); // homeowner, spouse, child, long_term_occupant, caregiver, other
            $table->string('relationship_label', 100);
            $table->string('pass_category', 50);
            $table->foreignId('gate_pass_id')->nullable()->constrained('gate_passes')->nullOnDelete();
            $table->json('permissions')->nullable();
            $table->json('access_schedule')->nullable();
            $table->string('status', 30)->default('active'); // active, suspended, expired
            $table->dateTime('valid_until')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('household_members');
        Schema::dropIfExists('households');
    }
};
