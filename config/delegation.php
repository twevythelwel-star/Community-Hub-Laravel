<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Longest-lived delegate gate pass
    |--------------------------------------------------------------------------
    |
    | A delegation may run until revoked, but no pass issued under one lives
    | longer than this; it is reissued from the delegation page. A homeowner
    | could otherwise hand a stranger a two-year estate pass.
    |
    */

    'max_pass_days' => (int) env('DELEGATION_MAX_PASS_DAYS', 365),

];
