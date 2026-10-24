<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | HOA dues are charged per property, so an invoice says which property it is
 | for, and every homeowner's property is on record in `properties`. Until now
 | an owner's only property was the lot and street on their account; this
 | records it, so an owner of several lots can have each one billed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['property_id', 'period_start']);
        });

        $homeowners = DB::table('users')
            ->where('role', 'Homeowner')
            ->whereNotNull('lot')
            ->where('lot', '!=', '')
            ->get(['id', 'lot', 'street']);

        foreach ($homeowners as $owner) {
            if (DB::table('properties')->where('owner_user_id', $owner->id)->exists()) {
                continue;
            }

            // A record of the same lot is this one, not a second lot. If it is
            // unowned it becomes theirs. If someone else owns it (two accounts
            // at one address, such as co-owners) it is left alone: recording it
            // twice would bill the lot twice, and the office decides who pays.
            $existing = DB::table('properties')
                ->where('lot_number', $owner->lot)
                ->where(fn ($q) => $q->where('street_address', $owner->street)->orWhereNull('street_address'))
                ->first(['id', 'owner_user_id']);

            if ($existing) {
                if ($existing->owner_user_id === null) {
                    DB::table('properties')->where('id', $existing->id)->update(['owner_user_id' => $owner->id, 'updated_at' => now()]);
                }

                continue;
            }

            do {
                $code = 'PROP-'.strtoupper(Str::random(8));
            } while (DB::table('properties')->where('property_code', $code)->exists());

            DB::table('properties')->insert([
                'owner_user_id' => $owner->id,
                'property_code' => $code,
                'lot_number' => $owner->lot,
                'street_address' => $owner->street,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['property_id', 'period_start']);
            $table->dropConstrainedForeignId('property_id');
        });
    }
};
