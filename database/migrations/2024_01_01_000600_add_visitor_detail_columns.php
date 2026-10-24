<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the visitor detail fields the registration form already displayed.
 *
 * The form in src/app/dashboard/visitors/page.tsx collected contact, vehicle,
 * ID type and ID number, but every one of those inputs was uncontrolled and the
 * "Save visitor" button had no handler — nothing was ever stored. These columns
 * give those fields somewhere to go, so the form works as it always appeared to.
 *
 * `expired_at` records the 12-hour no-show sweep, which previously ran in a
 * browser setInterval and only removed rows from local React state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('contact')->nullable()->after('name');
            $table->string('vehicle')->nullable()->after('contact');
            $table->string('id_type')->nullable()->after('vehicle');
            $table->string('id_number')->nullable()->after('id_type');
            $table->timestamp('expired_at')->nullable()->after('checked_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn(['contact', 'vehicle', 'id_type', 'id_number', 'expired_at']);
        });
    }
};
