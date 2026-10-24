<?php

namespace App\Services\Messaging;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a message through Twilio's Programmable Messaging REST API.
 *
 * Laravel's HTTP client rather than the Twilio SDK: it is one endpoint, it
 * adds no dependency, and Http::fake() makes it testable without the network.
 *
 * Logs carry the masked number and Twilio's message SID. The body is never
 * logged: it contains the visitor's name, the host's name and a guest-pass URL
 * that works for anyone holding it.
 */
class TwilioClient
{
    private const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

    /**
     * The Account SID is always needed (it is in the URL). Authentication is
     * either the account's Auth Token or an API key (an SK... SID and its
     * secret), which Twilio recommends because it can be revoked on its own
     * without rotating the account's master token.
     */
    public function hasCredentials(): bool
    {
        return filled(config('services.twilio.sid')) && $this->basicAuth() !== null;
    }

    /** @return array{0: string, 1: string}|null */
    private function basicAuth(): ?array
    {
        $apiKey = config('services.twilio.api_key');
        $apiSecret = config('services.twilio.api_secret');

        if (filled($apiKey) && filled($apiSecret)) {
            return [(string) $apiKey, (string) $apiSecret];
        }

        $token = config('services.twilio.token');

        return filled($token) ? [(string) config('services.twilio.sid'), (string) $token] : null;
    }

    /**
     * @param  array<string, string>  $params  Twilio parameters beyond To and From: Body, MediaUrl, ContentSid, ContentVariables.
     * @return string The SID of the message Twilio accepted.
     *
     * @throws MessageNotSent
     */
    public function send(string $channel, string $to, string $from, array $params): string
    {
        $sid = (string) config('services.twilio.sid');
        [$username, $password] = $this->basicAuth() ?? ['', ''];

        try {
            $response = Http::asForm()
                ->withBasicAuth($username, $password)
                ->timeout(15)
                ->post(sprintf(self::ENDPOINT, $sid), ['To' => $to, 'From' => $from, ...$params]);
        } catch (ConnectionException) {
            Log::warning("{$channel} could not reach Twilio", ['to' => PhoneNumber::mask($to)]);

            throw MessageNotSent::unreachable($channel);
        }

        if ($response->failed()) {
            $code = $response->json('code');

            Log::warning("{$channel} refused by Twilio", [
                'to' => PhoneNumber::mask($to),
                'status' => $response->status(),
                'code' => $code,
            ]);

            throw MessageNotSent::rejected($channel, $response->status(), is_numeric($code) ? (int) $code : null);
        }

        $messageSid = (string) $response->json('sid');

        Log::info("{$channel} accepted by Twilio", [
            'to' => PhoneNumber::mask($to),
            'sid' => $messageSid,
        ]);

        return $messageSid;
    }
}
