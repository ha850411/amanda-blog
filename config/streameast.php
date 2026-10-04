<?php

return [
    'enabled' => true,
    'base_url' => 'https://streameast.cool',
    'catalogs' => [
        'baseball' => ['/mlbstreams3'],
        'basketball' => ['/nbastreams3', '/wnbastreams2', '/ncaabstreams3'],
        'american-football' => ['/nflstreams3', '/cfbstreams3'],
        'hockey' => ['/nhlstreams3'],
        'fight' => ['/mmastreams3', '/boxingstreams3', '/wwestreams3'],
        'motor-sports' => ['/f1streams3'],
    ],
    'embed_hosts' => ['gooz.aapmains.net'],
    'match_tolerance_seconds' => 900,
    'cache_seconds' => 120,
    'stale_seconds' => 900,
];
