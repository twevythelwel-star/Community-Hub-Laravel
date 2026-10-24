<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Models\GatePass;
use RuntimeException;

/**
 * Builds a "Save to Google Wallet" link for a gate pass.
 *
 * The link carries a JWT signed (RS256) with the issuer's service-account
 * key. It holds the pass class and object themselves, so nothing has to be
 * created through the Wallet API first. Nothing is offered until
 * config('wallet.google') names an issuer and a readable key file.
 */
class GoogleWalletPass
{
    public const SAVE_URL = 'https://pay.google.com/gp/v/save/';

    public function __construct(private readonly WalletCredentialCode $codes) {}

    public function isConfigured(): bool
    {
        if (blank(config('wallet.google.issuer_id'))) {
            return false;
        }

        $key = $this->serviceAccount();

        return filled($key['client_email'] ?? null) && filled($key['private_key'] ?? null);
    }

    public function saveUrl(GatePass $pass): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Wallet is not configured.');
        }

        $account = $this->serviceAccount();
        $issuer = (string) config('wallet.google.issuer_id');
        $classId = $issuer.'.'.$this->idPart((string) config('wallet.google.class_suffix'));

        $claims = [
            'iss' => $account['client_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => now()->getTimestamp(),
            'origins' => [rtrim((string) config('app.url'), '/')],
            'payload' => [
                'genericClasses' => [['id' => $classId]],
                'genericObjects' => [$this->object($pass, $issuer, $classId)],
            ],
        ];

        return self::SAVE_URL.$this->jwt($claims, $account['private_key']);
    }

    /**
     * @return array<string, mixed>
     */
    private function object(GatePass $pass, string $issuer, string $classId): array
    {
        $text = fn (string $value): array => ['defaultValue' => ['language' => 'en-US', 'value' => $value]];

        $object = [
            'id' => $issuer.'.'.$this->idPart($pass->pass_id),
            'classId' => $classId,
            'state' => $pass->isActive() ? 'ACTIVE' : 'INACTIVE',
            'cardTitle' => $text((string) config('wallet.apple.organization_name', config('app.name'))),
            'header' => $text($pass->holder_name),
            'subheader' => $text($pass->property ?: 'Estate access'),
            'hexBackgroundColor' => '#0F172A',
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $this->codes->for($pass),
                'alternateText' => $pass->pass_id,
            ],
            'textModulesData' => [
                ['id' => 'category', 'header' => 'Category', 'body' => $pass->category->label()],
                ['id' => 'gate', 'header' => 'Gate', 'body' => $pass->designated_gate?->label() ?? 'All gates'],
            ],
        ];

        if ($pass->valid_until) {
            $object['validTimeInterval'] = ['end' => ['date' => $pass->valid_until->toIso8601String()]];
        }

        // Google fetches the logo itself, so only a public https address will do.
        $logo = asset('community-hub-app-icon-128.png');
        if (str_starts_with($logo, 'https://')) {
            $object['logo'] = ['sourceUri' => ['uri' => $logo], 'contentDescription' => $text('Logo')];
        }

        return $object;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function jwt(array $claims, string $privateKey): string
    {
        $encode = fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $unsigned = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            .'.'.$encode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The Google Wallet link could not be signed; check the service-account key.');
        }

        return $unsigned.'.'.$encode($signature);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceAccount(): array
    {
        $path = (string) config('wallet.google.service_account_key');
        if ($path === '') {
            return [];
        }

        if (! preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $path)) {
            $path = storage_path('app/private/'.$path);
        }

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Object and class IDs allow letters, digits, '.', '_' and '-' only. */
    private function idPart(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
    }
}
