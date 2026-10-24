<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renters, household members and household staff are all people who live or
 * work at a property. They move into one `occupants` table, told apart by
 * `type`; the Renter, HouseholdMember and Staff models stay as views of it.
 *
 * Renters keep their ids, so /renters/{id} links still resolve. Household
 * members and staff are shifted past the highest renter id (and staff past
 * the highest member id), which lets vehicles be remapped in one statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occupants', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30); // renter | household_member | staff

            // Shared
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->string('name');
            $table->string('status', 30);
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();

            // Renter: a stay at a homeowner's property
            $table->foreignId('homeowner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stay_type')->nullable();
            $table->string('contact')->nullable();
            $table->date('lease_start')->nullable();
            $table->date('lease_end')->nullable();
            $table->string('lot')->nullable();
            $table->string('street')->nullable();

            // Household member: someone on a household's roster
            $table->foreignId('household_id')->nullable()->constrained('households')->cascadeOnDelete();
            $table->string('role_in_household', 50)->nullable();
            $table->string('relationship_label', 100)->nullable();
            $table->string('pass_category', 50)->nullable();
            $table->foreignId('gate_pass_id')->nullable()->constrained('gate_passes')->nullOnDelete();
            $table->json('permissions')->nullable();
            $table->json('access_schedule')->nullable();
            $table->dateTime('valid_until')->nullable();

            // Staff: household or community staff, identified by document
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('job')->nullable();
            $table->string('id_type')->nullable();
            $table->string('id_number')->nullable();
            $table->date('id_expiry')->nullable();
            $table->string('id_image_url')->nullable();
            $table->string('photo_url')->nullable();
            $table->string('property_label')->nullable(); // free text, e.g. "Lot 42, Main St"

            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['homeowner_id', 'status']);
            $table->index(['type', 'lease_end']);
        });

        $memberOffset = (int) DB::table('renters')->max('id');
        $staffOffset = $memberOffset + (int) DB::table('household_members')->max('id');

        DB::table('renters')->orderBy('id')->chunkById(500, function ($rows) {
            DB::table('occupants')->insert($rows->map(fn ($r) => [
                'id' => $r->id,
                'type' => 'renter',
                'user_id' => $r->user_id,
                'property_id' => $r->property_id,
                'name' => $r->name,
                'status' => $r->status,
                'notes' => $r->notes,
                'homeowner_id' => $r->homeowner_id,
                'stay_type' => $r->stay_type,
                'contact' => $r->contact,
                'lease_start' => $r->lease_start,
                'lease_end' => $r->lease_end,
                'lot' => $r->lot,
                'street' => $r->street,
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ])->all());
        });

        DB::table('household_members')->orderBy('id')->chunkById(500, function ($rows) use ($memberOffset) {
            DB::table('occupants')->insert($rows->map(fn ($m) => [
                'id' => $m->id + $memberOffset,
                'type' => 'household_member',
                'user_id' => $m->user_id,
                'name' => $m->name,
                'status' => $m->status,
                'email' => $m->email,
                'phone' => $m->phone,
                'household_id' => $m->household_id,
                'role_in_household' => $m->role_in_household,
                'relationship_label' => $m->relationship_label,
                'pass_category' => $m->pass_category,
                'gate_pass_id' => $m->gate_pass_id,
                'permissions' => $m->permissions,
                'access_schedule' => $m->access_schedule,
                'valid_until' => $m->valid_until,
                'created_at' => $m->created_at,
                'updated_at' => $m->updated_at,
            ])->all());
        });

        DB::table('staff')->orderBy('id')->chunkById(500, function ($rows) use ($staffOffset) {
            DB::table('occupants')->insert($rows->map(fn ($s) => [
                'id' => $s->id + $staffOffset,
                'type' => 'staff',
                'name' => $s->name,
                'status' => $s->status,
                'added_by' => $s->added_by,
                'job' => $s->job,
                'id_type' => $s->id_type,
                'id_number' => $s->id_number,
                'id_expiry' => $s->id_expiry,
                'id_image_url' => $s->id_image_url,
                'photo_url' => $s->photo_url,
                'property_label' => $s->property,
                'created_at' => $s->created_at,
                'updated_at' => $s->updated_at,
            ])->all());
        });

        $this->resetSequence('occupants');
        $this->linkMembersAndStaffToProperties();

        // Vehicles pointed at household_members; point them at occupants.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['household_member_id']);
        });

        DB::table('vehicles')
            ->whereNotNull('household_member_id')
            ->update(['household_member_id' => DB::raw('household_member_id + '.$memberOffset)]);

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreign('household_member_id')->references('id')->on('occupants')->nullOnDelete();
        });

        Schema::drop('household_members');
        Schema::drop('renters');
        Schema::drop('staff');
    }

    public function down(): void
    {
        Schema::create('renters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('homeowner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('stay_type')->default('Long-term (Renter)');
            $table->string('contact')->nullable();
            $table->string('status')->default('Active');
            $table->date('lease_start');
            $table->date('lease_end');
            $table->string('lot')->nullable();
            $table->string('street')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'lease_end']);
            $table->index(['homeowner_id', 'status']);
        });

        Schema::create('household_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('email', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('role_in_household', 50);
            $table->string('relationship_label', 100);
            $table->string('pass_category', 50);
            $table->foreignId('gate_pass_id')->nullable()->constrained('gate_passes')->nullOnDelete();
            $table->json('permissions')->nullable();
            $table->json('access_schedule')->nullable();
            $table->string('status', 30)->default('active');
            $table->dateTime('valid_until')->nullable();
            $table->timestamps();
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('job');
            $table->string('id_type');
            $table->string('id_number');
            $table->date('id_expiry');
            $table->string('id_image_url')->nullable();
            $table->string('property');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('Active');
            $table->string('photo_url')->nullable();
            $table->timestamps();

            $table->index(['status', 'id_expiry']);
        });

        // Ids come back unchanged: occupant ids were unique, so each row can
        // keep the id it has now. Vehicles therefore need no remapping.
        $copy = function (string $type, string $table, array $columns, array $renamed = []) {
            DB::table('occupants')->where('type', $type)->orderBy('id')->chunkById(500, function ($rows) use ($table, $columns, $renamed) {
                DB::table($table)->insert($rows->map(function ($row) use ($columns, $renamed) {
                    $out = ['id' => $row->id, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at];
                    foreach ($columns as $column) {
                        $out[$column] = $row->{$column};
                    }
                    foreach ($renamed as $from => $to) {
                        $out[$to] = $row->{$from};
                    }

                    return $out;
                })->all());
            });
        };

        $copy('renter', 'renters', ['user_id', 'homeowner_id', 'property_id', 'name', 'stay_type', 'contact', 'status', 'lease_start', 'lease_end', 'lot', 'street', 'notes']);
        $copy('household_member', 'household_members', ['household_id', 'user_id', 'name', 'email', 'phone', 'role_in_household', 'relationship_label', 'pass_category', 'gate_pass_id', 'permissions', 'access_schedule', 'status', 'valid_until']);
        $copy('staff', 'staff', ['name', 'job', 'id_type', 'id_number', 'id_expiry', 'id_image_url', 'added_by', 'status', 'photo_url'], ['property_label' => 'property']);

        foreach (['renters', 'household_members', 'staff'] as $table) {
            $this->resetSequence($table);
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['household_member_id']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreign('household_member_id')->references('id')->on('household_members')->nullOnDelete();
        });

        Schema::drop('occupants');
    }

    /**
     * Household members and staff only named their property in free text
     * ("Unit Lot 42", "Lot 42, Royal Palm Drive"). Give them the property_id
     * renters already have: the sponsoring homeowner's property at that lot,
     * or their only property. Community staff ("Community Grounds") and
     * owners of several properties with no matching lot stay unset.
     */
    private function linkMembersAndStaffToProperties(): void
    {
        $bare = function (?string $label): string {
            $label = strtolower(trim(explode(',', (string) $label)[0]));

            while (preg_match('/^(unit|lot|#)\s*/', $label)) {
                $label = preg_replace('/^(unit|lot|#)\s*/', '', $label);
            }

            return $label;
        };

        $resolve = function (?int $ownerId, ?string $label) use ($bare): ?int {
            if (! $ownerId) {
                return null;
            }

            $owned = DB::table('properties')->where('owner_user_id', $ownerId)->orderBy('id')->get(['id', 'lot_number']);
            $match = filled($bare($label)) ? $owned->first(fn ($p) => $bare($p->lot_number) === $bare($label)) : null;

            return $match?->id ?? ($owned->count() === 1 && ! filled($bare($label)) ? $owned->first()->id : null);
        };

        $households = DB::table('households')->get(['id', 'primary_homeowner_id', 'property_number'])->keyBy('id');

        foreach (DB::table('occupants')->where('type', 'household_member')->get(['id', 'household_id']) as $member) {
            $household = $households->get($member->household_id);
            $propertyId = $household ? $resolve($household->primary_homeowner_id, $household->property_number) : null;

            if ($propertyId) {
                DB::table('occupants')->where('id', $member->id)->update(['property_id' => $propertyId]);
            }
        }

        foreach (DB::table('occupants')->where('type', 'staff')->get(['id', 'added_by', 'property_label']) as $staff) {
            if ($propertyId = $resolve($staff->added_by, $staff->property_label)) {
                DB::table('occupants')->where('id', $staff->id)->update(['property_id' => $propertyId]);
            }
        }
    }

    /** Rows were inserted with explicit ids; Postgres needs its sequence moved past them. */
    private function resetSequence(string $table): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 0) + 1, false)");
        }
    }
};
