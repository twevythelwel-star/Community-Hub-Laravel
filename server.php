<?php

/**
 * Router for PHP's built-in web server.
 *
 * `php artisan serve` cannot bind on this machine — Herd installs `php` as a
 * .bat wrapper, and Symfony Process loses the child, so Laravel reports
 * "Failed to listen on 127.0.0.1:<port> (reason: ?)" on every port. Raw
 * `php -S` binds fine, so .claude/launch.json runs that against this router
 * instead. Laravel shipped exactly this file before the slim skeleton dropped
 * it.
 *
 *     php -S 127.0.0.1:8000 -t public server.php
 */
$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
);

// If the file exists in the public directory and is not a directory, handle static asset delivery.
$publicFile = __DIR__.'/public'.$uri;

if ($uri !== '/' && file_exists($publicFile) && ! is_dir($publicFile)) {
    // If the built-in server's document root is already public/, return false to let PHP serve it natively.
    if (isset($_SERVER['DOCUMENT_ROOT']) && realpath($_SERVER['DOCUMENT_ROOT']) === realpath(__DIR__.'/public')) {
        return false;
    }

    // Otherwise, serve the static file directly so assets load reliably with any php -S invocation.
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'txt' => 'text/plain',
        'pdf' => 'application/pdf',
        'map' => 'application/json',
    ];

    $ext = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
    $mime = $mimeTypes[$ext] ?? (function_exists('mime_content_type') ? mime_content_type($publicFile) : null) ?: 'application/octet-stream';

    header("Content-Type: {$mime}");
    header('Content-Length: '.filesize($publicFile));
    readfile($publicFile);
    exit;
}

require_once __DIR__.'/public/index.php';
