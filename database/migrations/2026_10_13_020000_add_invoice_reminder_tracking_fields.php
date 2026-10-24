<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('invoices', 'last_reminded_at')) {
                    $table->timestamp('last_reminded_at')->nullable()->after('paid_at');
                }
                if (! Schema::hasColumn('invoices', 'reminder_count')) {
                    $table->unsignedInteger('reminder_count')->default(0)->after('last_reminded_at');
                }
            });
        }

        if (Schema::hasTable('notification_deliveries')) {
            Schema::table('notification_deliveries', function (Blueprint $table) {
                if (! Schema::hasColumn('notification_deliveries', 'invoice_id')) {
                    $table->foreignId('invoice_id')->nullable()->after('transaction_id')->constrained()->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_deliveries')) {
            Schema::table('notification_deliveries', function (Blueprint $table) {
                if (Schema::hasColumn('notification_deliveries', 'invoice_id')) {
                    $table->dropConstrainedForeignId('invoice_id');
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (Schema::hasColumn('invoices', 'reminder_count')) {
                    $table->dropColumn('reminder_count');
                }
                if (Schema::hasColumn('invoices', 'last_reminded_at')) {
                    $table->dropColumn('last_reminded_at');
                }
            });
        }
    }
};
