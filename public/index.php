<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Serve the maintenance page if the application is down for maintenance.
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
