<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Models\GatePass;

/**
 * The static code printed on a wallet pass (Apple, Google) and sent as its
 * NFC message: CHW1.<pass id>.<signature>.
 *
 * A wallet pass cannot rotate the way the in-app QR does, so it carries a
 * code that only this server can produce. Knowing or photographing a pass ID
 * is not enough to make one. A pass reported lost is revoked and replaced
 * under a new pass ID, so the old wallet code stops at the gate with it.
 */
class WalletCredentialCode
{
    public const PREFIX = 'CHW1.';

    /** Prefix on the NFC message, so the gate can log the tap as NFC. */
    public const NFC_PREFIX = 'NFC:';

    public function __construct(private readonly ?string $secret = null) {}

    public function for(GatePass $pass): string
    {
        return self::PREFIX.$pass->pass_id.'.'.$this->signature($pass->pass_id);
    }

    public function nfcMessageFor(GatePass $pass): string
    {
        return self::NFC_PREFIX.$this->for($pass);
    }

    /**
     * The pass ID a genuine code names, or null for anything else: a bare
     * pass ID, a code with a wrong or missing signature, or another format.
     */
    public function passIdFrom(string $code): ?string
    {
        $code = trim($code);

        if (str_starts_with($code, self::NFC_PREFIX)) {
            $code = substr($code, strlen(self::NFC_PREFIX));
        }

        if (! str_starts_with($code, self::PREFIX)) {
            return null;
        }

        $body = substr($code, strlen(self::PREFIX));
        $dot = strrpos($body, '.');
        if ($dot === false) {
            return null;
        }

        $passId = substr($body, 0, $dot);
        $signature = substr($body, $dot + 1);

        if ($passId === '' || ! hash_equals($this->signature($passId), $signature)) {
            return null;
        }

        return $passId;
    }

    public function isNfcMessage(string $code): bool
    {
        return str_starts_with(trim($code), self::NFC_PREFIX.self::PREFIX);
    }

    private function signature(string $passId): string
    {
        // A key of its own, derived from the gate pass secret, so a wallet
        // signature can never be mistaken for a gate token's.
        $key = hash_hmac('sha256', 'wallet-credential-code', (string) ($this->secret ?? config('gatepass.secret')), true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $passId, $key, true)), '+/', '-_'), '=');
    }
}
