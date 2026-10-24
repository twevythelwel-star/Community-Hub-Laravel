<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\TransactionEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles incoming Twilio messaging status callbacks.
 *
 * CRITICAL ARCHITECTURAL INVARIANT:
 * Twilio NEVER tells CommunityHub whether a payment succeeded.
 * The payment gateway / authoritative ledger establishes transaction truth (PAID).
 * CommunityHub then instructs Twilio to dispatch SMS/WhatsApp receipts.
 * This webhook tracks ONLY the delivery status of those messages
 * (e.g. queued -> sent -> delivered / undelivered / failed).
 */
class TwilioWebhookController extends Controller
{
    public function messagingStatus(Request $request): Response
    {
        $this->validateTwilioSignature($request);

        $messageSid = (string) $request->input('MessageSid');
        $messageStatus = (string) $request->input('MessageStatus', 'unknown');
        $to = (string) $request->input('To', '');
        $errorCode = $request->input('ErrorCode');
        $errorMessage = $request->input('ErrorMessage');

        if (empty($messageSid)) {
            return response('<Response></Response>', 200, ['Content-Type' => 'text/xml']);
        }

        Log::info("Twilio messaging status callback: {$messageSid} -> {$messageStatus}", [
            'to' => $to,
            'status' => $messageStatus,
            'error_code' => $errorCode,
        ]);

        // Locate any transaction event associated with this Twilio MessageSid
        $event = TransactionEvent::where('provider_event_id', $messageSid)->latest()->first();

        if ($event && $event->transaction) {
            $transaction = $event->transaction;

            // Invariant check: Twilio callback never changes the payment status of the transaction
            // Transaction status was set by the payment gateway/ledger and remains immutable from SMS/WhatsApp
            TransactionEvent::create([
                'transaction_id' => $transaction->id,
                'event_type' => 'twilio_delivery_status',
                'provider_event_id' => $messageSid,
                'status' => $transaction->status, // Transaction truth preserved
                'payload_reference' => sprintf(
                    'Twilio delivery update: %s%s',
                    $messageStatus,
                    $errorCode ? " (Error {$errorCode}: {$errorMessage})" : ''
                ),
                'occurred_at' => now(),
                'processed_at' => now(),
            ]);
        }

        return response('<Response></Response>', 200, ['Content-Type' => 'text/xml']);
    }

    /**
     * Validates X-Twilio-Signature: base64 HMAC-SHA1, keyed with the account's
     * Auth Token, over the full URL followed by each POST field (sorted by
     * name) as name+value. Twilio signs with the Auth Token only — never an
     * API key secret — so without one no callback can be trusted.
     */
    protected function validateTwilioSignature(Request $request): void
    {
        $token = (string) config('services.twilio.token');

        if ($token === '') {
            Log::warning('Twilio webhook rejected: no Auth Token configured to verify it');
            abort(403, 'Twilio is not configured');
        }

        $signature = (string) $request->header('X-Twilio-Signature');

        if ($signature === '') {
            Log::warning('Twilio webhook rejected: missing X-Twilio-Signature header');
            abort(403, 'Missing Twilio Signature');
        }

        $url = $request->fullUrl();
        $postData = $request->post();
        ksort($postData);

        $signed = $url;
        foreach ($postData as $name => $value) {
            $signed .= $name.(is_array($value) ? implode('', $value) : $value);
        }

        if (! hash_equals(base64_encode(hash_hmac('sha1', $signed, $token, true)), $signature)) {
            Log::warning('Twilio webhook rejected: invalid X-Twilio-Signature', [
                'url' => $url,
            ]);
            abort(403, 'Invalid Twilio Signature');
        }
    }
}
