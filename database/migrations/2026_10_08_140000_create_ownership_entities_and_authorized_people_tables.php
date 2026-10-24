<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ownership_entities')) {
            Schema::create('ownership_entities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('community_id')->nullable()->constrained()->nullOnDelete();
                $table->string('entity_type', 32)->default('individual'); // individual, company, trust, partnership, estate, nonprofit, other
                $table->string('legal_name');
                $table->string('trade_name')->nullable();
                $table->string('registration_number', 64)->nullable(); // Tax ID / TRN / Incorporation # / Trust Reg #
                $table->string('jurisdiction', 64)->nullable();
                $table->string('primary_email')->nullable();
                $table->string('primary_phone', 32)->nullable();
                $table->text('billing_address')->nullable();
                $table->string('status', 32)->default('active'); // active, pending_verification, archived
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['entity_type', 'status']);
            });
        }

        if (! Schema::hasTable('authorized_people')) {
            Schema::create('authorized_people', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ownership_entity_id')->constrained('ownership_entities')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('role', 32)->default('individual_owner'); // director, trustee, executor, property_manager, etc.
                $table->string('full_name');
                $table->string('email')->nullable();
                $table->string('phone', 32)->nullable();
                $table->string('id_document_type', 32)->nullable();
                $table->string('id_document_number', 64)->nullable();
                $table->json('permissions')->nullable();
                $table->boolean('is_primary_contact')->default(false);
                $table->boolean('is_signatory')->default(false);
                $table->date('valid_from')->nullable();
                $table->date('valid_until')->nullable();
                $table->string('status', 32)->default('active'); // active, suspended, revoked
                $table->string('verification_status', 32)->default('unverified');
                $table->timestamps();

                $table->index(['ownership_entity_id', 'role']);
                $table->index(['user_id', 'status']);
            });
        }

        if (Schema::hasTable('properties') && ! Schema::hasColumn('properties', 'ownership_entity_id')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->foreignId('ownership_entity_id')->nullable()->after('owner_user_id')->constrained('ownership_entities')->nullOnDelete();
            });
        }

        // Backfill existing properties tied to user owners into individual ownership entities
        if (Schema::hasTable('properties') && Schema::hasTable('users')) {
            $properties = DB::table('properties')
                ->whereNotNull('owner_user_id')
                ->whereNull('ownership_entity_id')
                ->get();

            $entityCache = [];

            foreach ($properties as $property) {
                $userId = $property->owner_user_id;

                if (! isset($entityCache[$userId])) {
                    $user = DB::table('users')->where('id', $userId)->first();
                    if ($user) {
                        $entityId = DB::table('ownership_entities')->insertGetId([
                            'community_id' => $property->community_id,
                            'entity_type' => 'individual',
                            'legal_name' => $user->name,
                            'primary_email' => $user->email,
                            'primary_phone' => $user->phone ?? null,
                            'status' => 'active',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        DB::table('authorized_people')->insert([
                            'ownership_entity_id' => $entityId,
                            'user_id' => $user->id,
                            'role' => 'individual_owner',
                            'full_name' => $user->name,
                            'email' => $user->email,
                            'phone' => $user->phone ?? null,
                            'permissions' => json_encode(['manage_passes', 'manage_billing', 'manage_occupants', 'vote', 'sign_legal']),
                            'is_primary_contact' => true,
                            'is_signatory' => true,
                            'status' => 'active',
                            'verification_status' => 'verified',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $entityCache[$userId] = $entityId;
                    }
                }

                if (isset($entityCache[$userId])) {
                    DB::table('properties')
                        ->where('id', $property->id)
                        ->update(['ownership_entity_id' => $entityCache[$userId]]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('properties') && Schema::hasColumn('properties', 'ownership_entity_id')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropConstrainedForeignId('ownership_entity_id');
            });
        }

        Schema::dropIfExists('authorized_people');
        Schema::dropIfExists('ownership_entities');
    }
};
