<?php

/*
|-------------------------------------------------------------------------------
| Legal and business identity
|-------------------------------------------------------------------------------
|
| Every value here is a PLACEHOLDER and must be replaced before the policy
| pages are published. They are deliberately obvious rather than plausible:
| a policy that names the wrong legal entity, or gives a jurisdiction nobody
| checked, is worse than one that is visibly unfinished.
|
| `LEGAL_DETAILS_COMPLETE` gates the policy pages. While it is false, each page
| renders a banner saying the details have not been filled in, so nobody
| mistakes a template for a published policy.
|
*/

return [

    'complete' => (bool) env('LEGAL_DETAILS_COMPLETE', false),

    'entity' => [
        // The registered legal name, not the app name or the estate's name.
        'name' => env('LEGAL_ENTITY_NAME', '[REGISTERED LEGAL NAME]'),
        'type' => env('LEGAL_ENTITY_TYPE', '[e.g. Homeowners Association / Limited Company]'),
        'registration_number' => env('LEGAL_REGISTRATION_NUMBER', '[REGISTRATION NUMBER]'),
        'registered_address' => env('LEGAL_REGISTERED_ADDRESS', '[REGISTERED ADDRESS]'),
        'jurisdiction' => env('LEGAL_JURISDICTION', '[GOVERNING JURISDICTION]'),
    ],

    'contact' => [
        'general_email' => env('LEGAL_CONTACT_EMAIL', '[CONTACT EMAIL]'),
        'privacy_email' => env('LEGAL_PRIVACY_EMAIL', '[PRIVACY CONTACT EMAIL]'),
        'postal_address' => env('LEGAL_POSTAL_ADDRESS', '[POSTAL ADDRESS FOR WRITTEN REQUESTS]'),
        'phone' => env('LEGAL_CONTACT_PHONE', '[CONTACT TELEPHONE]'),
    ],

    /*
    | Kept as dates rather than "last updated" prose so the pages cannot drift
    | out of sync with each other.
    */
    'effective_from' => env('LEGAL_EFFECTIVE_FROM', '2026-09-20'),

    /*
    | Minimum age for an account. The application has no age gate and no
    | date-of-birth field, so it must not be offered to children until one of
    | those exists — see the children's-data section of the privacy policy.
    */
    'minimum_age' => (int) env('LEGAL_MINIMUM_AGE', 18),

    /*
    | Third parties that receive personal data. Keep this list honest: it is
    | quoted verbatim by the privacy and cookie policies, and it is the list a
    | resident is entitled to rely on.
    */
    'processors' => [
        [
            'name' => 'Stripe',
            'purpose' => 'Card payment processing, when card payment is enabled.',
            'data' => 'Name, email address, invoice reference and amount. Card details are entered on Stripe and never reach this application.',
            'active' => false,
        ],
        [
            'name' => 'Google (Gemini API)',
            'purpose' => 'Drafting community notice text from a document an administrator pastes in.',
            'data' => 'Only the document text submitted for drafting. Sent only by administrators who have given consent, and only when an API key is configured.',
            'active' => false,
        ],
    ],

];
