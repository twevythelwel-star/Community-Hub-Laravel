<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What a guest pass tells the visitor
    |--------------------------------------------------------------------------
    |
    | Shown on every guest pass when a visitor's own entry does not say
    | otherwise. Nothing here has a default: an unset item is simply not shown.
    | The pass used to fall back to invented details — a 555 gatehouse number,
    | "911" for ambulance and fire (Jamaica's is 110), an AED location, an
    | assembly point and estate rules — which a guest in trouble would act on.
    |
    */

    'parking_instructions' => env('VISITOR_PASS_PARKING_INSTRUCTIONS'),

    // A list of ['title' => ..., 'rule' => ...]. Set here, not in .env.
    'community_rules' => [],

    'emergency_info' => [
        'security_phone' => env('VISITOR_PASS_SECURITY_PHONE'),
        'emergency_services' => env('VISITOR_PASS_EMERGENCY_SERVICES'), // e.g. "119 (Police) / 110 (Fire & Ambulance)"
        'aed_location' => env('VISITOR_PASS_AED_LOCATION'),
        'assembly_point' => env('OCCUPANCY_ASSEMBLY_POINT'),
    ],

];
