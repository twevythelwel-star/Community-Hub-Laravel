<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentChannelSetting extends Model
{
    use HasFactory;

    public const MODE_API = 'API';

    public const MODE_HOSTED_CHECKOUT = 'HOSTED_CHECKOUT';

    public const MODE_WEBHOOK = 'WEBHOOK';

    public const MODE_BANK_RECONCILIATION = 'BANK_RECONCILIATION';

    public const MODE_MANUAL_VERIFICATION = 'MANUAL_VERIFICATION';

    protected $fillable = [
        'channel_key',
        'enabled',
        'display_label',
        'instructions',
        'account_identifier',
        'integration_mode',
        'fee_surcharge_percent',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'fee_surcharge_percent' => 'float',
    ];

    public static function defaultChannels(): array
    {
        return [
            ['channel_key' => 'card', 'display_label' => 'Credit/Debit Card', 'enabled' => true, 'integration_mode' => self::MODE_HOSTED_CHECKOUT, 'instructions' => 'Instant checkout powered by Stripe.'],
            ['channel_key' => 'apple_pay', 'display_label' => 'Apple Pay', 'enabled' => true, 'integration_mode' => self::MODE_WEBHOOK, 'instructions' => '1-tap biometric payment on Apple devices.'],
            ['channel_key' => 'google_pay', 'display_label' => 'Google Pay', 'enabled' => true, 'integration_mode' => self::MODE_WEBHOOK, 'instructions' => 'Fast checkout via Google Pay.'],
            ['channel_key' => 'samsung_wallet', 'display_label' => 'Samsung Wallet', 'enabled' => true, 'integration_mode' => self::MODE_WEBHOOK, 'instructions' => 'Pay seamlessly with Samsung Wallet.'],
            ['channel_key' => 'bank_wire', 'display_label' => 'Direct Bank Transfer', 'enabled' => true, 'integration_mode' => self::MODE_BANK_RECONCILIATION, 'instructions' => 'NCB Account #102938475, Branch 001. Use invoice reference.', 'account_identifier' => 'NCB #102938475'],
            ['channel_key' => 'zelle', 'display_label' => 'Zelle', 'enabled' => true, 'integration_mode' => self::MODE_BANK_RECONCILIATION, 'instructions' => 'Send to payments@cypressbay.org with Lot # in memo.', 'account_identifier' => 'payments@cypressbay.org'],
            ['channel_key' => 'cash_app', 'display_label' => 'Cash App', 'enabled' => true, 'integration_mode' => self::MODE_BANK_RECONCILIATION, 'instructions' => 'Pay to $CypressBayHOA with your Lot number.', 'account_identifier' => '$CypressBayHOA'],
            ['channel_key' => 'qr_code', 'display_label' => 'QR Code Scan', 'enabled' => true, 'integration_mode' => self::MODE_HOSTED_CHECKOUT, 'instructions' => 'Scan with mobile camera to pay instantly.'],
            ['channel_key' => 'nfc_pos', 'display_label' => 'Tap to Pay / Contactless', 'enabled' => true, 'integration_mode' => self::MODE_API, 'instructions' => 'Tap physical card on supported card-present POS terminal.'],
            ['channel_key' => 'cash_office', 'display_label' => 'Cash at Office', 'enabled' => true, 'integration_mode' => self::MODE_MANUAL_VERIFICATION, 'instructions' => 'Pay in-person at the administration desk. Receipt provided.'],
            ['channel_key' => 'wallet', 'display_label' => 'Community Wallet', 'enabled' => true, 'integration_mode' => self::MODE_API, 'instructions' => 'Instant deduction from your community credit balance.'],
        ];
    }
}
