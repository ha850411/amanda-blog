<?php

return [
    // Player links expire and are revalidated on every page/media request.
    'lifetime_seconds' => 21600,

    // Add exact URLs only after observing video playback in the current sandbox.
    // The known embed.st source rejects sandboxed playback. Other sources have
    // not yet passed verification, so none are offered as in-site players.
    'verified_embed_urls' => [],

    'manifest_cache_store' => 'sports_manifests',
    // Optional exact integrations; Streameast now resolves matched event channels on play.
    'hls_sources' => [],
];
