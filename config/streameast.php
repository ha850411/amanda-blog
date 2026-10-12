<?php

return [
    'enabled' => true,
    'base_url' => 'https://streameast.cool',
    'catalogs' => [
        'baseball' => ['/mlbstreams3'],
        'basketball' => ['/nbastreams3'],
    ],
    'embed_hosts' => ['gooz.aapmains.net'],
    'match_tolerance_seconds' => 900,
    'cache_seconds' => 120,
    'stale_seconds' => 900,
];
