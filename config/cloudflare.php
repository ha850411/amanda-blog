<?php

return [
    'api_token' => env('CLOUDFLARE_ANALYTICS_API_TOKEN'),
    // Public identifiers verified in the site's Cloudflare dashboard.
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID', 'abcff8b627068eccd2c3d48b43b7a6bf'),
    // Web Analytics site tag, not the public beacon token or the API token.
    'site_tag' => env('CLOUDFLARE_WEB_ANALYTICS_SITE_TAG', '24dc41967c4346ac8b5ddc0b7faf13a0'),
    'hostname' => env('CLOUDFLARE_ANALYTICS_HOSTNAME', 'amanda-blog.com'),
    'cache_seconds' => 300,
    'timeout_seconds' => 20,
    'max_range_days' => 31,
];
