<?php

return [
    // Public publisher ID verified in the amanda-blog.com AdSense account.
    // Keep the default in source so the existing CI/CD deploy can activate it.
    'publisher_id' => env('ADSENSE_PUBLISHER_ID', 'pub-8869697978199559'),

    // Verification and ads.txt remain available while ad delivery is disabled.
    'enabled' => env('ADSENSE_ENABLED', true),
];
