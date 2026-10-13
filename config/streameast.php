<?php

return [
    'enabled' => true,
    'base_url' => 'https://streameast.cool',
    'catalogs' => [
        'baseball' => ['/mlbstreams3'],
        'basketball' => ['/nbastreams3'],
    ],
    'embed_hosts' => ['gooz.aapmains.net'],
    // Exact origins observed on the current embed/master/media playlists.
    'entry_hosts' => ['chatgpt.hereisman.net'],
    'playlist_hosts' => ['pl.playlist3.space', 'pl.playlist5.space', 'pl.kamfir5.space', 'pl.whywelove2.space', 'pl.wearestrong1.space'],
    'media_hosts' => ['a.newstore1.space', 'a.newstore4.space', 'a.newstore5.space'],
    'source_cache_seconds' => 45,
    'source_stale_seconds' => 180,
    'event_lifetime_seconds' => 21600,
    'match_tolerance_seconds' => 900,
    'cache_seconds' => 120,
    'stale_seconds' => 900,
];
