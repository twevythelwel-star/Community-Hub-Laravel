<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Models\GatePass;
use RuntimeException;
use ZipArchive;

/**
 * Builds a signed Apple Wallet pass (.pkpass) for a gate pass.
 *
 * A .pkpass is a zip of pass.json, its images, a manifest of their SHA-1
 * hashes, and a detached CMS signature of that manifest made with the Pass
 * Type ID certificate, chained to Apple's WWDR certificate. Wallet refuses a
 * pass without one, so nothing is offered until config('wallet.apple') holds
 * every value it needs.
 */
class AppleWalletPass
{
    /** Pixel sizes Wallet asks for, by file name. */
    private const IMAGES = [
        'icon.png' => 29,
        'icon@2x.png' => 58,
        'icon@3x.png' => 87,
        'logo.png' => 50,
        'logo@2x.png' => 100,
        'logo@3x.png' => 150,
    ];

    public function __construct(private readonly WalletCredentialCode $codes) {}

    public function isConfigured(): bool
    {
        $config = config('wallet.apple');

        return filled($config['pass_type_identifier'] ?? null)
            && filled($config['team_identifier'] ?? null)
            && is_file($this->path($config['certificate'] ?? null))
            && is_file($this->path($config['wwdr_certificate'] ?? null));
    }

    /**
     * @return string The .pkpass file's bytes.
     */
    public function build(GatePass $pass): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Apple Wallet is not configured.');
        }

        $files = ['pass.json' => json_encode($this->passJson($pass), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        foreach (self::IMAGES as $name => $size) {
            $files[$name] = $this->icon($size);
        }

        $manifest = json_encode(array_map('sha1', $files), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $files['manifest.json'] = $manifest;
        $files['signature'] = $this->sign($manifest);

        return $this->zip($files);
    }

    /**
     * @return array<string, mixed>
     */
    private function passJson(GatePass $pass): array
    {
        $config = config('wallet.apple');
        $code = $this->codes->for($pass);

        $json = [
            'formatVersion' => 1,
            'passTypeIdentifier' => $config['pass_type_identifier'],
            'teamIdentifier' => $config['team_identifier'],
            'serialNumber' => $pass->pass_id,
            'organizationName' => $config['organization_name'],
            'description' => "Estate access pass for {$pass->holder_name}",
            'logoText' => $config['organization_name'],
            'foregroundColor' => 'rgb(255, 255, 255)',
            'backgroundColor' => 'rgb(15, 23, 42)',
            'labelColor' => 'rgb(212, 175, 55)',
            'generic' => [
                'primaryFields' => [
                    ['key' => 'holder', 'label' => 'HOLDER', 'value' => $pass->holder_name],
                ],
                'secondaryFields' => [
                    ['key' => 'category', 'label' => 'CATEGORY', 'value' => $pass->category->label()],
                    ['key' => 'property', 'label' => 'RESIDENCE', 'value' => $pass->property ?: 'Unassigned'],
                ],
                'auxiliaryFields' => [
                    ['key' => 'gate', 'label' => 'GATE', 'value' => $pass->designated_gate?->label() ?? 'All gates'],
                ],
                'backFields' => [
                    ['key' => 'pass_id', 'label' => 'PASS ID', 'value' => $pass->pass_id],
                    ['key' => 'lost', 'label' => 'LOST YOUR PHONE?', 'value' => 'Report the pass lost in the Community Hub wallet. The gate stops accepting it at once.'],
                ],
            ],
            'barcodes' => [
                [
                    'format' => 'PKBarcodeFormatQR',
                    'message' => $code,
                    'messageEncoding' => 'iso-8859-1',
                    'altText' => $pass->pass_id,
                ],
            ],
        ];

        if ($pass->valid_until) {
            $json['expirationDate'] = $pass->valid_until->toIso8601String();
        }

        if (! $pass->isActive()) {
            $json['voided'] = true;
        }

        return $json;
    }

    private function sign(string $manifest): string
    {
        $config = config('wallet.apple');

        $bundle = [];
        if (! openssl_pkcs12_read((string) file_get_contents($this->path($config['certificate'])), $bundle, (string) $config['certificate_password'])) {
            throw new RuntimeException('The Apple Wallet certificate could not be read; check the .p12 file and its password.');
        }

        $in = tempnam(sys_get_temp_dir(), 'pkmanifest_');
        $out = tempnam(sys_get_temp_dir(), 'pksignature_');

        try {
            file_put_contents($in, $manifest);

            $signed = openssl_cms_sign(
                $in,
                $out,
                $bundle['cert'],
                $bundle['pkey'],
                [],
                OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
                OPENSSL_ENCODING_DER,
                $this->path($config['wwdr_certificate']),
            );

            if (! $signed) {
                throw new RuntimeException('The Apple Wallet pass could not be signed: '.openssl_error_string());
            }

            return (string) file_get_contents($out);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pkpass_');

        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($files as $name => $contents) {
                $zip->addFromString($name, $contents);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function icon(int $size): string
    {
        $source = imagecreatefrompng(public_path('community-hub-app-icon-128.png'));
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function path(?string $path): string
    {
        if (blank($path)) {
            return '';
        }

        return preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $path) ? $path : storage_path('app/private/'.$path);
    }
}
