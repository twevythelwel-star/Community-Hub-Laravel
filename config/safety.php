<?php

/*
 | Discreet distress / silent assistance.
 */
return [

    /*
     | Assistance options by credential holder. Community operators can turn
     | off a type for a class of credential without changing resident data.
     | Account holders still intersect this policy with their own preferences.
     */
    'community_options' => array_replace([
        'homeowner' => ['security', 'police', 'medical', 'fire', 'contact', 'emergency_services'],
        'renter' => ['security', 'police', 'medical', 'fire', 'contact', 'emergency_services'],
        'long_term_occupant' => ['security', 'police', 'medical', 'fire', 'contact', 'emergency_services'],
        'short_term_guest' => ['security', 'emergency_services'],
        'visitor' => ['security', 'emergency_services'],
        'contractor' => ['security', 'emergency_services'],
        'staff' => ['security', 'emergency_services'],
        'security' => ['security', 'emergency_services'],
        'domestic_staff' => ['security', 'emergency_services'],
        'legacy_contact' => ['security', 'police', 'medical', 'fire', 'contact', 'emergency_services'],
        'delegate' => ['security', 'police', 'medical', 'fire', 'contact', 'emergency_services'],
        'authorized_representative' => ['security', 'police', 'medical', 'fire', 'contact', 'emergency_services'],
    ], array_filter([
        'homeowner' => env('SAFETY_HOMEOWNER_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_HOMEOWNER_OPTIONS'))) : null,
        'renter' => env('SAFETY_RENTER_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_RENTER_OPTIONS'))) : null,
        'long_term_occupant' => env('SAFETY_LONG_TERM_OCCUPANT_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_LONG_TERM_OCCUPANT_OPTIONS'))) : null,
        'short_term_guest' => env('SAFETY_SHORT_TERM_GUEST_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_SHORT_TERM_GUEST_OPTIONS'))) : null,
        'visitor' => env('SAFETY_VISITOR_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_VISITOR_OPTIONS'))) : null,
        'contractor' => env('SAFETY_CONTRACTOR_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_CONTRACTOR_OPTIONS'))) : null,
        'staff' => env('SAFETY_STAFF_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_STAFF_OPTIONS'))) : null,
        'security' => env('SAFETY_SECURITY_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_SECURITY_OPTIONS'))) : null,
        'domestic_staff' => env('SAFETY_DOMESTIC_STAFF_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_DOMESTIC_STAFF_OPTIONS'))) : null,
        'legacy_contact' => env('SAFETY_LEGACY_CONTACT_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_LEGACY_CONTACT_OPTIONS'))) : null,
        'delegate' => env('SAFETY_DELEGATE_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_DELEGATE_OPTIONS'))) : null,
        'authorized_representative' => env('SAFETY_AUTHORIZED_REPRESENTATIVE_OPTIONS') ? array_map('trim', explode(',', env('SAFETY_AUTHORIZED_REPRESENTATIVE_OPTIONS'))) : null,
    ], fn ($options) => $options !== null)),

    'guest_assistance_options' => [
        'security' => true,
        'emergency_services' => true,
    ],
    'emergency_services_enabled' => env('SAFETY_EMERGENCY_SERVICES_ENABLED', false),

    /*
     | How long a credential stays armed after the discreet QR gesture: its
     | next gate scan in this window raises silent assistance at that gate.
     */
    'armed_minutes' => (int) env('SAFETY_ARMED_MINUTES', 15),

    /*
     | How long the resident holds their QR code to raise silent assistance.
     | Long enough not to happen by accident, short enough to do unseen.
     */
    'qr_hold_seconds' => (int) env('SAFETY_QR_HOLD_SECONDS', 3),

    /*
     | Key for the duress PIN's keyed hash. A separate secret from the app key
     | so the PINs can be re-keyed on their own; falls back to the app key.
     */
    'duress_pin_key' => env('SAFETY_DURESS_PIN_KEY', env('APP_KEY')),

    /*
     | Emergency services escalation configuration.
     | This is a safeguard - actual emergency services calling is done by
     | authorized personnel, not automatically by the system.
     */
    /*
     | Emergency service contact numbers (for authorized escalation only).
     | These are used for reference by security personnel, not for automatic calling.
     */
    'emergency_contacts' => [
        'police' => env('SAFETY_POLICE_NUMBER'),
        'medical' => env('SAFETY_MEDICAL_NUMBER'),
        'fire' => env('SAFETY_FIRE_NUMBER'),
    ],

    /*
     | Optional public emergency number for a resident's direct call action.
     | Configure it for the community's jurisdiction; never infer a number.
     */
    'public_emergency_number' => env('SAFETY_PUBLIC_EMERGENCY_NUMBER'),

    /*
     | Require explicit confirmation before emergency services escalation.
     | This prevents accidental escalation to emergency services.
     */
    'require_emergency_confirmation' => env('SAFETY_REQUIRE_EMERGENCY_CONFIRMATION', true),

    /*
     | Maximum seconds an active distress alert can remain unacknowledged before
     | automatic escalation to Level 2 (Security Escalation). Default: 60 seconds.
     */
    'acknowledgment_timeout_seconds' => (int) env('SAFETY_ACKNOWLEDGMENT_TIMEOUT_SECONDS', 60),

];
