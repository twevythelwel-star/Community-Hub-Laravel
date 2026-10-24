<?php

namespace App\Services\Credentials;

use App\Models\GatePassNonce;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

class GatePassTokenSigner
{
    public function __construct(
        private readonly ?string $secret = null,
    ) {
        if (blank($this->secret ?? config('gatepass.secret'))) {
            throw new RuntimeException(
                'GATE_ENGINE_SECRET is not set. Generate one with: php artisan gatepass:secret'
            );
        }
    }

    public function key(): string
    {
        return $this->secret ?? config('gatepass.secret');
    }

    /**
     * Real HMAC-SHA256 over a canonical (key-sorted) JSON encoding, so the
     * signature is stable regardless of key order.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        return hash_hmac('sha256', $this->canonicalJson($payload), $this->key());
    }

    public function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalise($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalise($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->canonicalise($item), $value);
    }

    public function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $encoded): ?string
    {
        $base64 = strtr($encoded, '-_', '+/');
        $padded = str_pad($base64, (int) (ceil(strlen($base64) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Records a nonce as used. Returns null if this is the first sighting, or
     * the original timestamp when it has been seen before.
     */
    public function claimNonce(string $nonce, string $passId, int $expiresAtTs): ?CarbonImmutable
    {
        try {
            GatePassNonce::create([
                'nonce' => $nonce,
                'pass_id' => $passId,
                'first_seen_at' => now(),
                // Keep the row a little past the window so late duplicates are caught.
                'expires_at' => CarbonImmutable::createFromTimestamp($expiresAtTs)->addMinutes(5),
            ]);

            return null;
        } catch (UniqueConstraintViolationException) {
            $existing = GatePassNonce::where('nonce', $nonce)->first();

            return $existing
                ? CarbonImmutable::parse($existing->first_seen_at)
                : CarbonImmutable::now();
        }
    }

    /** Drops nonce rows whose window has closed. Called by the scheduler. */
    public function pruneNonces(): int
    {
        return GatePassNonce::expired()->delete();
    }
}
