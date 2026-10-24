<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | The property a stay is for. Access & People already read
 | $renter->property_id to scope a renter's staff and contractors, but the
 | column did not exist, so it was always null and the page fell back to
 | matching any property by lot number. An owner may hold several properties;
 | the stay says which one is rented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renters', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('homeowner_id')->constrained()->nullOnDelete();
        });

        // Existing stays: the homeowner's property at the stay's lot, or their
        // only one. An owner of several with no lot to match is left unset.
        $bare = fn (?string $lot) => strtolower(preg_replace('/^(unit|lot|#)\s*/i', '', trim((string) $lot)));

        foreach (DB::table('renters')->whereNotNull('homeowner_id')->get(['id', 'homeowner_id', 'lot']) as $stay) {
            $owned = DB::table('properties')->where('owner_user_id', $stay->homeowner_id)->orderBy('id')->get(['id', 'lot_number']);

            $property = filled($stay->lot)
                ? $owned->first(fn ($p) => $bare($p->lot_number) === $bare($stay->lot))
                : ($owned->count() === 1 ? $owned->first() : null);

            if ($property) {
                DB::table('renters')->where('id', $stay->id)->update(['property_id' => $property->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('renters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });
    }
};
