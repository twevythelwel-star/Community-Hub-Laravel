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
        // 1. Access Risk Incidents & Suspicious Activity
        Schema::create('access_risk_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('pass_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('gate')->default('GATE-01');
            $table->string('flag_type')->index(); // EXCESSIVE_FAILURES, EXPIRED_ATTEMPT, REVOKED_ATTEMPT, SIMULTANEOUS_MULTI_GATE, UNUSUAL_HOURS, UNUSUAL_FREQUENCY, REPEATED_REJECTIONS, CREDENTIAL_SHARING
            $table->string('risk_level')->default('MEDIUM')->index(); // LOW, MEDIUM, HIGH, CRITICAL
            $table->string('title');
            $table->text('description');
            $table->json('evidence')->nullable();
            $table->string('status')->default('NEW')->index(); // NEW, INVESTIGATING, CONFIRMED, DISMISSED, RESOLVED, LOCKED_DOWN
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        // 2. Multi-Tier Access Approval Requests
        Schema::create('access_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->string('category')->index(); // visitor, contractor, long_term_occupant, legacy_contact, caregiver
            $table->string('applicant_name');
            $table->string('applicant_phone')->nullable();
            $table->string('applicant_email')->nullable();
            $table->foreignId('homeowner_id')->constrained('users')->cascadeOnDelete();
            $table->string('property_number');
            $table->foreignId('gate_pass_id')->nullable()->constrained('gate_passes')->nullOnDelete();
            $table->string('workflow_type'); // visitor_instant, contractor_property_admin, occupant_community_admin, legacy_id_verification
            $table->string('current_stage')->default('homeowner_approval'); // homeowner_approval, admin_approval, id_verification, completed, rejected
            $table->string('status')->default('pending')->index(); // pending, approved, rejected, expired
            $table->json('approval_chain')->nullable();
            $table->timestamp('requested_from')->nullable();
            $table->timestamp('requested_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // 3. Digital Lease & Authorization Compliance Documents
        Schema::create('authorization_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('document_type')->index(); // lease, authorization_letter, id_verification, insurance, contractor_certificate, other
            $table->foreignId('household_id')->nullable()->constrained('households')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('gate_pass_id')->nullable()->constrained('gate_passes')->nullOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained('access_approval_requests')->nullOnDelete();
            $table->string('holder_name');
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->string('mime_type')->default('application/pdf');
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->index();
            $table->string('status')->default('valid')->index(); // valid, expiring_soon, expired, superseded
            $table->string('verification_status')->default('verified'); // verified, pending_review, rejected
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // 4. Complete Forensic Access Audit Timeline
        Schema::create('access_audit_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->string('pass_id')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('holder_name');
            $table->string('gate')->default('GATE-01');
            $table->string('event_type')->index(); // CREDENTIAL_SCANNED, IDENTITY_VERIFIED, GUARD_APPROVED, GATE_OPENED, TRANSIT_DETECTED, GATE_CLOSED, CREDENTIAL_DENIED, CHECKED_OUT, REVOCATION_ENFORCED
            $table->string('severity')->default('INFO'); // INFO, NOTICE, WARNING, CRITICAL
            $table->string('headline');
            $table->text('description')->nullable();
            $table->string('actor_type')->default('SYSTEM'); // SYSTEM, GUARD, RESIDENT, SENSOR
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('telemetry')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_audit_timeline_events');
        Schema::dropIfExists('authorization_documents');
        Schema::dropIfExists('access_approval_requests');
        Schema::dropIfExists('access_risk_incidents');
    }
};
