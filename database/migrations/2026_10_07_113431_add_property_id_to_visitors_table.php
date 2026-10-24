<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Which of the host's properties the visitor is coming to. An owner may hold
 | several; the visitor's pass names this one, not the host's account address.
 | Null for existing visitors, which keep the host's address as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('homeowner_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });
    }
};
