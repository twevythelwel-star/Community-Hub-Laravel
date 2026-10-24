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
        Schema::create('delegated_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grantor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();

            // Contact identity
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('relationship'); // Family member, Attorney, Caregiver, Executor, Property manager, Trusted friend, Emergency representative, Other

            // Controlled delegation boundary
            $table->string('access_level'); // Emergency Contact, Limited Delegate, Property Delegate, Emergency Delegate, Legacy Delegate, Full Authorized Representative
            $table->json('permissions')->nullable(); // Granular scoped permissions e.g. ['gate_access', 'visitor_authorization', 'emergency_communications', ...]
            $table->string('status')->default('active'); // pending, active, suspended, revoked, expired

            // Time windows & constraints
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Activation governance
            $table->string('activation_method')->default('immediate'); // immediate, emergency_trigger, incapacity_proof, admin_approval_required
            $table->string('approval_status')->default('approved'); // not_required, pending, approved, rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Emergency activation state
            $table->boolean('is_emergency_active')->default(false);
            $table->timestamp('emergency_activated_at')->nullable();
            $table->timestamp('emergency_expires_at')->nullable();
            $table->string('emergency_reason')->nullable();
            $table->foreignId('emergency_activated_by')->nullable()->constrained('users')->nullOnDelete();

            // Revocation & tracking
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->string('invite_token', 64)->nullable()->unique();
            $table->text('security_notes')->nullable();

            $table->timestamps();

            $table->index(['grantor_user_id', 'status']);
            $table->index(['delegate_user_id', 'status']);
            $table->index(['access_level', 'status']);
            $table->index(['is_emergency_active', 'status']);
        });

        Schema::create('delegated_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegated_access_id')->constrained('delegated_accesses')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type'); // created, invited, accepted, permissions_updated, emergency_activated, emergency_deactivated, gate_scanned, revoked, expired, action_executed, suspicious_attempt
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['delegated_access_id', 'event_type']);
            $table->index('occurred_at');
        });

        Schema::create('emergency_continuity_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('primary_delegate_id')->nullable()->constrained('delegated_accesses')->nullOnDelete();
            $table->foreignId('secondary_delegate_id')->nullable()->constrained('delegated_accesses')->nullOnDelete();
            $table->json('activation_conditions')->nullable(); // medical_emergency, incapacity, extended_absence, legal_probate
            $table->string('required_verification')->default('admin_verification'); // admin_verification, two_officer_signoff, legal_affidavit
            $table->json('authorized_actions')->nullable(); // gate_access, visitor_management, property_maintenance, emergency_dispatch
            $table->unsignedSmallInteger('max_duration_days')->default(30);
            $table->boolean('requires_admin_approval')->default(true);
            $table->boolean('notify_homeowner_on_trigger')->default(true);
            $table->boolean('notify_community_security')->default(true);
            $table->json('notification_recipients')->nullable();
            $table->text('special_instructions')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergency_continuity_plans');
        Schema::dropIfExists('delegated_access_events');
        Schema::dropIfExists('delegated_accesses');
    }
};
