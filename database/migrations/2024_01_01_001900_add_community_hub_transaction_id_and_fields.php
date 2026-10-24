<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('transaction_id', 32)->nullable()->unique()->after('id');
            $table->string('user_code', 32)->nullable()->after('user_id');
            $table->string('property_code', 32)->nullable()->after('user_code');
            $table->string('community_code', 32)->nullable()->after('property_code');
            $table->string('purpose', 100)->nullable()->after('community_code');
            $table->string('payment_method', 50)->nullable()->after('payment_channel');
            $table->string('provider_reference')->nullable()->after('reference');
            $table->string('provider_status', 50)->nullable()->after('status');
        });

        // Backfill existing rows with canonical CommunityHub identifiers
        $rows = DB::table('transactions')->get();
        foreach ($rows as $index => $row) {
            $year = $row->created_at ? date('Y', strtotime($row->created_at)) : date('Y');
            $seq = $index + 1;
            $txId = sprintf('CH-%04d-%010d', (int) $year, (int) $seq);

            $userCode = $row->user_id ? sprintf('USR-%06d', $row->user_id) : 'USR-000001';
            $propCode = 'PROP-00481';
            if ($row->user_id) {
                $user = DB::table('users')->where('id', $row->user_id)->first();
                if ($user && $user->lot && preg_match('/\d+/', $user->lot, $m)) {
                    $propCode = sprintf('PROP-%05d', (int) $m[0]);
                }
            }

            $methodMap = [
                'apple_pay' => 'Apple Pay',
                'google_pay' => 'Google Pay',
                'samsung_wallet' => 'Samsung Wallet',
                'card' => 'Credit / Debit Card',
                'stripe_card' => 'Credit / Debit Card',
                'bank_wire' => 'Bank Transfer',
                'zelle' => 'Zelle',
                'cash_app' => 'Cash App',
                'qr_code' => 'QR Code',
                'nfc_pos' => 'NFC Tap',
                'cash_office' => 'Cash at Office',
                'wallet' => 'Community Wallet',
            ];

            DB::table('transactions')->where('id', $row->id)->update([
                'transaction_id' => $txId,
                'user_code' => $userCode,
                'property_code' => $propCode,
                'community_code' => 'COMM-001',
                'purpose' => 'HOA Assessment',
                'payment_method' => $methodMap[$row->payment_channel] ?? ucwords(str_replace('_', ' ', $row->payment_channel)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'transaction_id',
                'user_code',
                'property_code',
                'community_code',
                'purpose',
                'payment_method',
                'provider_reference',
                'provider_status',
            ]);
        });
    }
};
