<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise People, Property Relationships & Access Authorization Architecture.
 *
 * 1. Person / User Identity:
 *    One consistent identity across properties and relationship types without duplicate records.
 * 2. Property Relationship:
 *    Resident · Renter · Household · Staff · Guest (ownership remains separate from occupancy).
 * 3. Access Authorization:
 *    Scope · Permissions · Approval · Expiry · Revocation. Cross-property access denied unless authorized.
 * 4. Credentials → Access Events:
 *    Links gate passes (QR, NFC, PIN) to authorizations and preserves audit history.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. People (Unified Physical Identity) ─────────────────────────
        if (! Schema::hasTable('people')) {
            Schema::create('people', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('email', 150)->nullable()->index();
                $table->string('phone', 50)->nullable()->index();
                $table->string('id_type', 50)->nullable();
                $table->string('id_number', 100)->nullable();
                $table->date('id_expiry')->nullable();
                $table->string('id_image_url')->nullable();
                $table->string('photo_url')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        // ── 2. Property Relationships ─────────────────────────────────────
        if (! Schema::hasTable('property_relationships')) {
            Schema::create('property_relationships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
                $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
                $table->string('type', 30); // resident | renter | household | staff | guest
                $table->string('role_label', 100)->default('Occupant');
                $table->string('status', 30)->default('active'); // active | inactive | suspended | terminated
                $table->foreignId('sponsor_person_id')->nullable()->constrained('people')->nullOnDelete();
                $table->foreignId('sponsor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['property_id', 'type', 'status']);
                $table->index(['person_id', 'property_id']);
                $table->index(['source_type', 'source_id']);
            });
        }

        // ── 3. Access Authorizations ──────────────────────────────────────
        if (! Schema::hasTable('access_authorizations')) {
            Schema::create('access_authorizations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
                $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
                $table->foreignId('property_relationship_id')->nullable()->constrained('property_relationships')->nullOnDelete();
                $table->string('scope', 30)->default('property'); // property | community | zones
                $table->json('allowed_zones')->nullable();
                $table->json('permissions')->nullable();
                $table->json('access_rules')->nullable();
                $table->string('approval_status', 30)->default('approved'); // pending | approved | rejected
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('approved_at')->nullable();
                $table->dateTime('valid_from');
                $table->dateTime('valid_until')->nullable();
                $table->string('status', 30)->default('active'); // active | pending | expired | revoked | suspended
                $table->boolean('is_revoked')->default(false);
                $table->dateTime('revoked_at')->nullable();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('revocation_reason')->nullable();
                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['person_id', 'status', 'is_revoked']);
                $table->index(['property_id', 'status']);
                $table->index(['scope', 'status']);
                $table->index(['source_type', 'source_id']);
            });
        }

        // ── 4. Extend Gate Passes ─────────────────────────────────────────
        if (Schema::hasTable('gate_passes') && ! Schema::hasColumn('gate_passes', 'person_id')) {
            Schema::table('gate_passes', function (Blueprint $table) {
                $table->foreignId('person_id')->nullable()->after('user_id')->constrained('people')->nullOnDelete();
                $table->foreignId('access_authorization_id')->nullable()->after('person_id')->constrained('access_authorizations')->nullOnDelete();
                $table->foreignId('property_id')->nullable()->after('access_authorization_id')->constrained('properties')->nullOnDelete();
            });
        }

        // ── 5. Backfill Existing Records (Preserve all data) ──────────────
        $this->backfillExistingRecords();
    }

    public function down(): void
    {
        if (Schema::hasTable('gate_passes') && Schema::hasColumn('gate_passes', 'person_id')) {
            Schema::table('gate_passes', function (Blueprint $table) {
                $table->dropForeign(['person_id']);
                $table->dropForeign(['access_authorization_id']);
                $table->dropForeign(['property_id']);
                $table->dropColumn(['person_id', 'access_authorization_id', 'property_id']);
            });
        }

        Schema::dropIfExists('access_authorizations');
        Schema::dropIfExists('property_relationships');
        Schema::dropIfExists('people');
    }

    /**
     * Backfill existing records to ensure zero data loss and immediate compatibility.
     */
    protected function backfillExistingRecords(): void
    {
        $now = Carbon::now();

        // 1. Backfill Users (Homeowners, Residents)
        $users = DB::table('users')->get();
        $userPersonMap = [];

        foreach ($users as $user) {
            $personId = DB::table('people')->insertGetId([
                'user_id' => $user->id,
                'name' => $user->display_name ?: $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo_url' => $user->avatar_url,
                'created_at' => $user->created_at ?? $now->toDateTimeString(),
                'updated_at' => $user->updated_at ?? $now->toDateTimeString(),
            ]);

            $userPersonMap[$user->id] = $personId;

            // If user owns or occupies properties, create Resident relationships
            $properties = DB::table('properties')
                ->where('owner_user_id', $user->id)
                ->orWhere('primary_occupant_id', $user->id)
                ->get();

            foreach ($properties as $prop) {
                $relId = DB::table('property_relationships')->insertGetId([
                    'person_id' => $personId,
                    'property_id' => $prop->id,
                    'type' => 'resident',
                    'role_label' => ($prop->owner_user_id === $user->id) ? 'Owner Resident' : 'Resident',
                    'status' => 'active',
                    'start_date' => $now->toDateString(),
                    'source_type' => 'App\\Models\\User',
                    'source_id' => $user->id,
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ]);

                DB::table('access_authorizations')->insert([
                    'person_id' => $personId,
                    'property_id' => $prop->id,
                    'property_relationship_id' => $relId,
                    'scope' => 'property',
                    'allowed_zones' => json_encode(['Main Gate']),
                    'permissions' => json_encode(['gate_access', 'visitor_authorization', 'amenity_access']),
                    'access_rules' => json_encode([]),
                    'approval_status' => 'approved',
                    'valid_from' => $now->copy()->subYear()->toDateTimeString(),
                    'valid_until' => null,
                    'status' => 'active',
                    'is_revoked' => false,
                    'source_type' => 'App\\Models\\User',
                    'source_id' => $user->id,
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ]);
            }
        }

        // 2. Backfill Occupants (Renters, Household Members, Staff)
        if (Schema::hasTable('occupants')) {
            $occupants = DB::table('occupants')->get();

            foreach ($occupants as $occ) {
                $personId = null;
                if (! empty($occ->user_id) && isset($userPersonMap[$occ->user_id])) {
                    $personId = $userPersonMap[$occ->user_id];
                } elseif (! empty($occ->email)) {
                    $personId = DB::table('people')->where('email', $occ->email)->value('id');
                }

                if (! $personId) {
                    $personId = DB::table('people')->insertGetId([
                        'user_id' => $occ->user_id,
                        'name' => $occ->name,
                        'email' => $occ->email,
                        'phone' => $occ->phone,
                        'id_type' => $occ->id_type ?? null,
                        'id_number' => $occ->id_number ?? null,
                        'id_expiry' => $occ->id_expiry ?? null,
                        'id_image_url' => $occ->id_image_url ?? null,
                        'photo_url' => $occ->photo_url ?? null,
                        'notes' => $occ->notes ?? null,
                        'created_at' => $occ->created_at ?? $now->toDateTimeString(),
                        'updated_at' => $occ->updated_at ?? $now->toDateTimeString(),
                    ]);
                }

                if ($occ->property_id) {
                    $relType = match ($occ->type) {
                        'renter' => 'renter',
                        'household_member' => 'household',
                        'staff' => 'staff',
                        default => 'resident',
                    };

                    $roleLabel = match ($occ->type) {
                        'renter' => $occ->stay_type ? "Renter ({$occ->stay_type})" : 'Tenant',
                        'household_member' => $occ->relationship_label ?: ($occ->role_in_household ?: 'Household Member'),
                        'staff' => $occ->job ?: 'Staff',
                        default => 'Occupant',
                    };

                    $startDate = $occ->lease_start ? Carbon::parse($occ->lease_start)->toDateString() : $now->toDateString();
                    $endDate = $occ->lease_end ? Carbon::parse($occ->lease_end)->toDateString() : null;

                    $relId = DB::table('property_relationships')->insertGetId([
                        'person_id' => $personId,
                        'property_id' => $occ->property_id,
                        'type' => $relType,
                        'role_label' => $roleLabel,
                        'status' => strtolower($occ->status ?? 'active') === 'active' ? 'active' : 'inactive',
                        'sponsor_user_id' => $occ->homeowner_id ?? ($occ->added_by ?? null),
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'source_type' => 'App\\Models\\Occupant',
                        'source_id' => $occ->id,
                        'created_at' => $occ->created_at ?? $now->toDateTimeString(),
                        'updated_at' => $occ->updated_at ?? $now->toDateTimeString(),
                    ]);

                    $validFrom = $occ->lease_start
                        ? Carbon::parse($occ->lease_start)->startOfDay()->toDateTimeString()
                        : $now->toDateTimeString();

                    $validUntil = null;
                    if ($occ->lease_end) {
                        $validUntil = Carbon::parse($occ->lease_end)->endOfDay()->toDateTimeString();
                    } elseif (! empty($occ->valid_until)) {
                        $validUntil = Carbon::parse($occ->valid_until)->toDateTimeString();
                    }

                    $accessRules = json_encode([]);
                    if (! empty($occ->access_schedule)) {
                        $accessRules = is_string($occ->access_schedule) ? $occ->access_schedule : json_encode($occ->access_schedule);
                    }

                    DB::table('access_authorizations')->insert([
                        'person_id' => $personId,
                        'property_id' => $occ->property_id,
                        'property_relationship_id' => $relId,
                        'scope' => 'property',
                        'allowed_zones' => json_encode(['Main Gate']),
                        'permissions' => json_encode(['gate_access']),
                        'access_rules' => $accessRules,
                        'approval_status' => 'approved',
                        'valid_from' => $validFrom,
                        'valid_until' => $validUntil,
                        'status' => strtolower($occ->status ?? 'active') === 'active' ? 'active' : 'expired',
                        'is_revoked' => false,
                        'source_type' => 'App\\Models\\Occupant',
                        'source_id' => $occ->id,
                        'created_at' => $occ->created_at ?? $now->toDateTimeString(),
                        'updated_at' => $occ->updated_at ?? $now->toDateTimeString(),
                    ]);
                }
            }
        }

        // 3. Backfill Visitors (Guests)
        if (Schema::hasTable('visitors')) {
            $visitors = DB::table('visitors')->get();

            foreach ($visitors as $v) {
                $personId = null;
                if (! empty($v->contact) && filter_var($v->contact, FILTER_VALIDATE_EMAIL)) {
                    $personId = DB::table('people')->where('email', $v->contact)->value('id');
                }

                if (! $personId) {
                    $personId = DB::table('people')->insertGetId([
                        'name' => $v->name,
                        'email' => filter_var($v->contact, FILTER_VALIDATE_EMAIL) ? $v->contact : null,
                        'phone' => ! filter_var($v->contact, FILTER_VALIDATE_EMAIL) ? $v->contact : null,
                        'id_type' => $v->id_type ?? null,
                        'id_number' => $v->id_number ?? null,
                        'id_image_url' => $v->id_image_url ?? null,
                        'notes' => $v->notes ?? null,
                        'created_at' => $v->created_at ?? $now->toDateTimeString(),
                        'updated_at' => $v->updated_at ?? $now->toDateTimeString(),
                    ]);
                }

                if ($v->property_id) {
                    $startDate = $v->expected_at ? Carbon::parse($v->expected_at)->toDateString() : $now->toDateString();
                    $endDate = $v->expired_at ? Carbon::parse($v->expired_at)->toDateString() : null;

                    $relId = DB::table('property_relationships')->insertGetId([
                        'person_id' => $personId,
                        'property_id' => $v->property_id,
                        'type' => 'guest',
                        'role_label' => $v->type ?: 'Guest',
                        'status' => $v->is_blocked ? 'suspended' : 'active',
                        'sponsor_user_id' => $v->homeowner_id,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'source_type' => 'App\\Models\\Visitor',
                        'source_id' => $v->id,
                        'created_at' => $v->created_at ?? $now->toDateTimeString(),
                        'updated_at' => $v->updated_at ?? $now->toDateTimeString(),
                    ]);

                    $validFrom = $v->expected_at ? Carbon::parse($v->expected_at)->toDateTimeString() : $now->toDateTimeString();
                    $validUntil = $v->expired_at ? Carbon::parse($v->expired_at)->toDateTimeString() : null;

                    DB::table('access_authorizations')->insert([
                        'person_id' => $personId,
                        'property_id' => $v->property_id,
                        'property_relationship_id' => $relId,
                        'scope' => 'property',
                        'allowed_zones' => json_encode(['Main Gate']),
                        'permissions' => json_encode(['gate_access']),
                        'access_rules' => json_encode([]),
                        'approval_status' => 'approved',
                        'valid_from' => $validFrom,
                        'valid_until' => $validUntil,
                        'status' => $v->is_blocked ? 'revoked' : 'active',
                        'is_revoked' => (bool) $v->is_blocked,
                        'source_type' => 'App\\Models\\Visitor',
                        'source_id' => $v->id,
                        'created_at' => $v->created_at ?? $now->toDateTimeString(),
                        'updated_at' => $v->updated_at ?? $now->toDateTimeString(),
                    ]);
                }
            }
        }

        // 4. Link Gate Passes to Person, Authorization and Property
        $gatePasses = DB::table('gate_passes')->get();
        foreach ($gatePasses as $gp) {
            $personId = null;
            $propertyId = null;
            $authId = null;

            if ($gp->user_id && isset($userPersonMap[$gp->user_id])) {
                $personId = $userPersonMap[$gp->user_id];
            }

            // Find matching authorization
            if ($personId) {
                $auth = DB::table('access_authorizations')->where('person_id', $personId)->first();
                if ($auth) {
                    $authId = $auth->id;
                    $propertyId = $auth->property_id;
                }
            }

            if ($personId || $authId || $propertyId) {
                DB::table('gate_passes')->where('id', $gp->id)->update([
                    'person_id' => $personId,
                    'access_authorization_id' => $authId,
                    'property_id' => $propertyId,
                ]);
            }
        }
    }
};
