<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renters', function (Blueprint $table) {
            $table->foreignId('homeowner_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('stay_type')->default('Long-term (Renter)')->after('name'); // Long-term (Renter) | Short-term (Airbnb)
            $table->string('contact')->nullable()->after('stay_type');
            $table->text('notes')->nullable()->after('street');

            $table->index(['homeowner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('renters', function (Blueprint $table) {
            $table->dropForeign(['homeowner_id']);
            $table->dropIndex(['homeowner_id', 'status']);
            $table->dropColumn(['homeowner_id', 'stay_type', 'contact', 'notes']);
        });
    }
};
