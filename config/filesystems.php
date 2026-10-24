<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        // Amazon Web Services S3
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        // DigitalOcean Spaces (S3-compatible API)
        'spaces' => [
            'driver' => 's3',
            'key' => env('DO_SPACES_KEY'),
            'secret' => env('DO_SPACES_SECRET'),
            'region' => env('DO_SPACES_REGION', 'nyc3'),
            'bucket' => env('DO_SPACES_BUCKET'),
            'endpoint' => env('DO_SPACES_ENDPOINT', 'https://'.env('DO_SPACES_REGION', 'nyc3').'.digitaloceanspaces.com'),
            'url' => env('DO_SPACES_URL'),
            'visibility' => 'public',
            'use_path_style_endpoint' => false,
            'throw' => false,
        ],

        // Google Cloud Storage (GCS)
        'gcs' => [
            'driver' => 's3',
            'key' => env('GCS_KEY'),
            'secret' => env('GCS_SECRET'),
            'region' => env('GCS_REGION', 'auto'),
            'bucket' => env('GCS_BUCKET'),
            'endpoint' => env('GCS_ENDPOINT', 'https://storage.googleapis.com'),
            'url' => env('GCS_URL'),
            'use_path_style_endpoint' => false,
            'throw' => false,
        ],

        // Microsoft Azure Blob Storage
        'azure' => [
            'driver' => 'local', // Or azure driver when league/flysystem-azure-blob-storage is bound
            'root' => storage_path('app/azure-cache'),
            'name' => env('AZURE_STORAGE_NAME'),
            'key' => env('AZURE_STORAGE_KEY'),
            'container' => env('AZURE_STORAGE_CONTAINER'),
            'url' => env('AZURE_STORAGE_URL'),
            'throw' => false,
        ],

        // Dedicated Media Disk
        'media' => [
            'driver' => 'local',
            'root' => storage_path('app/public/media'),
            'url' => env('APP_URL').'/storage/media',
            'visibility' => 'public',
            'throw' => false,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
