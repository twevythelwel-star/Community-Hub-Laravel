<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('muster_sessions')) {
            Schema::create('muster_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('incident_type', 40)->default('fire'); // fire, hurricane, earthquake, flood, security_incident, drill, other
                $table->string('title');
                $table->string('status', 24)->default('active'); // active, resolved
                $table->string('assembly_point');
                $table->text('notes')->nullable();
                $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'incident_type']);
            });
        }

        if (! Schema::hasTable('muster_roll_calls')) {
            Schema::create('muster_roll_calls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('muster_session_id')->constrained('muster_sessions')->cascadeOnDelete();
                $table->string('occupant_id', 64)->index(); // pass_123, visitor_45, user_78
                $table->string('occupant_name');
                $table->string('pass_id', 64)->nullable();
                $table->string('unit', 64)->nullable()->index();
                $table->string('category', 40)->default('visitors'); // residents, long_term_guests, visitors, staff, contractors, legacy_contacts
                $table->string('status', 32)->default('missing'); // safe, missing, evacuated, needs_assistance, checked_out, unverified
                $table->string('contact')->nullable(); // a phone, or an email for residents
                $table->string('vehicle', 64)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('marked_at')->nullable();
                $table->timestamps();

                $table->index(['muster_session_id', 'status']);
                $table->unique(['muster_session_id', 'occupant_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('muster_roll_calls');
        Schema::dropIfExists('muster_sessions');
    }
};
