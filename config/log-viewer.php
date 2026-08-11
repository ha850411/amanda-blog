<?php

use App\Http\Middleware\AdminMiddleware;
use Opcodes\LogViewer\Http\Middleware\EnsureFrontendRequestsAreStateful;

return [
    'enabled' => env('LOG_VIEWER_ENABLED', true),
    'require_auth_in_production' => true,
    'route_path' => 'log-viewer',
    'timezone' => env('LOG_VIEWER_TIMEZONE', 'Asia/Taipei'),
    'cache_driver' => env('LOG_VIEWER_CACHE_DRIVER', 'file'),

    'middleware' => [
        'web',
        AdminMiddleware::class,
    ],

    'api_middleware' => [
        EnsureFrontendRequestsAreStateful::class,
        AdminMiddleware::class,
    ],

    'include_files' => [
        'webhook*.log',
        'laravel*.log',
    ],
];
