<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stale check-ins
    |--------------------------------------------------------------------------
    |
    | A visitor, contractor, short-term guest or staff member checked in longer
    | ago than this, and never checked out, is no longer counted as on the
    | property. They are not dropped: an emergency roll call lists them as
    | "unverified", since leaving without checking out and still being inside
    | look the same from the gate. Residents and long-term occupants are never
    | stale; they live here.
    |
    */

    'stale_after_hours' => (int) env('OCCUPANCY_STALE_AFTER_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Default assembly point
    |--------------------------------------------------------------------------
    |
    | Where residents are told to go when an emergency muster starts without
    | one named. Leave unset to make whoever starts a muster name it: the
    | fallback used to be an invented "Central Park East Muster Field".
    |
    */

    'assembly_point' => env('OCCUPANCY_ASSEMBLY_POINT'),

];
