<?php

return [
    // Cached player links only. All playback traffic goes from the client to the provider.
    'lifetime_seconds' => 21600,

    // Add exact URLs only after observing video playback in the current sandbox.
    // The known embed.st source rejects sandboxed playback. Other sources have
    // not yet passed verification, so none are offered as in-site players.
    'verified_embed_urls' => [],
];
