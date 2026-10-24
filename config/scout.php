<?php

/*
| Only what differs from Laravel Scout's defaults (vendor/laravel/scout/config/scout.php);
| the package merges the rest in.
*/
return [
    // Search the application's own database; the package default is an in-memory collection.
    'driver' => env('SCOUT_DRIVER', 'database'),
];
