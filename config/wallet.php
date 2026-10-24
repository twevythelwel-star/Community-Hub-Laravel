<?php

/*
|--------------------------------------------------------------------------
| Mobile wallet passes
|--------------------------------------------------------------------------
| Each wallet is offered to residents only once every value it needs is set.
| Until then its button is hidden and its endpoint answers 404.
*/

return [

    /*
    | Apple Wallet (.pkpass). From an Apple Developer account:
    | - a Pass Type ID (Certificates, Identifiers & Profiles → Identifiers),
    | - its certificate exported from Keychain as a .p12, with its password,
    | - Apple's WWDR intermediate certificate (G4) as a .pem,
    | - the team ID shown on the membership page.
    | Certificate paths are absolute, or relative to storage/app/private.
    */
    'apple' => [
        'pass_type_identifier' => env('APPLE_WALLET_PASS_TYPE_ID'),
        'team_identifier' => env('APPLE_WALLET_TEAM_ID'),
        'certificate' => env('APPLE_WALLET_CERTIFICATE'),
        'certificate_password' => env('APPLE_WALLET_CERTIFICATE_PASSWORD'),
        'wwdr_certificate' => env('APPLE_WALLET_WWDR_CERTIFICATE'),
        'organization_name' => env('APPLE_WALLET_ORGANIZATION', env('APP_NAME', 'Community Hub')),
    ],

    /*
    | Google Wallet ("Save to Google Wallet" link). From the Google Pay &
    | Wallet Console: the issuer ID, and a service account with the Wallet
    | Object Issuer role, whose JSON key file is given here.
    */
    'google' => [
        'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),
        'service_account_key' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_KEY'),
        'class_suffix' => env('GOOGLE_WALLET_CLASS_SUFFIX', 'estate_access'),
    ],

];
