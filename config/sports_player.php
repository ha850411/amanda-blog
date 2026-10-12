<?php

return [
    // Player links expire and are revalidated on every page/media request.
    'lifetime_seconds' => 21600,

    // Add exact URLs only after observing video playback in the current sandbox.
    // The known embed.st source rejects sandboxed playback. Other sources have
    // not yet passed verification, so none are offered as in-site players.
    'verified_embed_urls' => [],

    'manifest_cache_store' => 'sports_manifests',
    // Exact event/source mappings observed working; do not infer other channel URLs.
    'hls_sources' => [
        'https://gooz.aapmains.net/new-stream-embed/57972?ad=111' => [
            'event_id' => 'milwaukee-brewers-vs-los-angeles-dodgers-2616724',
            'manifest_url' => 'https://pl.wearestrong1.space/playlist/57972/a.newstore1.space/caxi',
            'allowed_prefixes' => [
                'https://pl.wearestrong1.space/playlist/57972/',
                'https://a.newstore1.space/scripts/NTc5NzI=/',
            ],
            'expires_at' => '2026-10-13T10:00:00+08:00',
        ],
    ],
];
