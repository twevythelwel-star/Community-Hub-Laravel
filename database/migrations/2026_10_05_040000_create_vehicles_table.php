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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('household_member_id')->nullable()->constrained('household_members')->nullOnDelete();
            $table->string('property', 64)->default('Unit 14');
            $table->string('license_plate', 32)->index();
            $table->string('jurisdiction', 64)->default('Jamaica');
            $table->string('make', 64);
            $table->string('model', 64);
            $table->string('color', 48);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('parking_location', 128)->nullable();
            $table->boolean('is_ev')->default(false);
            $table->boolean('is_temporary')->default(false);
            $table->timestamp('valid_until')->nullable();
            $table->boolean('anpr_enabled')->default(true);
            $table->string('status', 32)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['license_plate', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
