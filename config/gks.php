<?php

return [
    'enabled' => true,
    // User requires direct browser-to-CDN media; no server relay fallback.
    // GOLF rejects this site's Origin, so keep it disabled until direct playback is verified.
    'golf_enabled' => false,
    'golf_mapping_cache_seconds' => 120,
    // Only verified formats with direct browser-to-CDN media delivery.
    'providers' => ['admin', 'hotel'],
    'stream_numbers' => [1],
    'source_cache_seconds' => 45,
    'event_lifetime_seconds' => 21600,
    'media_hosts' => ['p16-common-sign.tiktokcdn.com', 'p19-common-sign.tiktokcdn.com'],
];
