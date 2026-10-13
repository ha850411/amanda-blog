<?php

return [
    'enabled' => true,
    'base_url' => 'https://mlbwebcast.com',
    // Catalog dates/times are Eastern time, without an AM/PM suffix.
    'timezone' => 'America/New_York',
    'cache_seconds' => 90,
    'source_cache_seconds' => 45,
    'event_lifetime_seconds' => 21600,
    'stream_hosts' => ['s3.thegamehd.top', 's5.thegamehd.top'],
];
