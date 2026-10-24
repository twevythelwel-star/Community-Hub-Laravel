<?php

return [
    /*
     | Google AI (Gemini) — replaces the Genkit googleAI() plugin configured in
     | src/ai/genkit.ts. Used by App\Services\NotificationTargetingService.
     */
    'googleai' => [
        'key' => env('GOOGLE_AI_API_KEY'),
        'model' => env('GOOGLE_AI_MODEL', 'gemini-2.0-flash'),
        'endpoint' => env('GOOGLE_AI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Stripe
    |---------------------------------------------------------------------------
    |
    | This block did not exist, while StripePaymentService read
    | config('services.stripe.secret') to decide whether to talk to Stripe.
    | The key was therefore always null, the live branch was unreachable
    | whatever the operator put in .env, and every checkout silently took the
    | simulated path that marked invoices paid without charging anyone.
    |
    | Leave unset and the Stripe routes 404 rather than pretending to settle.
    |
    */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS & WhatsApp (Twilio)
    |--------------------------------------------------------------------------
    |
    | Used by App\Services\SmsService and WhatsAppService. With the account
    | SID and token plus a sender, that channel is enabled; without them it is
    | disabled in the UI and the services refuse rather than pretend to send.
    |
    | from                  SMS sender: a Twilio number or Messaging Service.
    | whatsapp_from         WhatsApp sender, e.g. +14155238886 (the sandbox).
    | whatsapp_content_sid  Approved template (HX...) for visitor passes. WhatsApp
    |                       refuses free text to anyone who has not messaged
    |                       the business in 24 hours; see WhatsAppService.
    | default_country_code  Prefixed to 10-digit numbers written without one.
    |                       1 is the North American plan, which covers Jamaica.
    |
    */

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        // Either the Auth Token, or an API key pair (preferred: revocable on its own).
        'token' => env('TWILIO_TOKEN'),
        'api_key' => env('TWILIO_API_KEY'),
        'api_secret' => env('TWILIO_API_SECRET'),
        'from' => env('TWILIO_FROM'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
        'whatsapp_content_sid' => env('TWILIO_WHATSAPP_CONTENT_SID'),
        'default_country_code' => env('SMS_DEFAULT_COUNTRY_CODE', '1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Notifications (WebPush / FCM)
    |--------------------------------------------------------------------------
    */
    'push' => [
        'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
        'fcm_server_key' => env('FCM_SERVER_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Incoming webhooks (POST /api/v1/webhooks/incoming/{service})
    |--------------------------------------------------------------------------
    |
    | List each sender in INCOMING_WEBHOOK_SERVICES and give it its own
    | {SERVICE}_WEBHOOK_SECRET, e.g. INCOMING_WEBHOOK_SERVICES=acme with
    | ACME_WEBHOOK_SECRET=... . A sender not listed here is refused.
    |
    | WebhookApiController used to call env() itself for whatever {service}
    | the URL named. That returns null under `php artisan config:cache`, so
    | every incoming webhook 404'd in production, and with the cache off any
    | *_WEBHOOK_SECRET became a valid key — including the outbound
    | notification secret that every receiver of our webhooks holds.
    |
    */
    'webhooks' => (function (): array {
        $services = array_filter(array_map(
            fn (string $service) => strtolower(trim($service)),
            explode(',', (string) env('INCOMING_WEBHOOK_SERVICES', '')),
        ));

        $webhooks = [];
        foreach ($services as $service) {
            $webhooks[$service] = ['secret' => env(strtoupper($service).'_WEBHOOK_SECRET')];
        }

        return $webhooks;
    })(),
];
