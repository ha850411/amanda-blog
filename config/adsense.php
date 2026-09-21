<?php

return [
    // Public publisher ID verified in the amanda-blog.com AdSense account.
    // Keep the default in source so the existing CI/CD deploy can activate it.
    'publisher_id' => env('ADSENSE_PUBLISHER_ID', 'pub-8869697978199559'),

    // Verification and ads.txt remain available while ad delivery is disabled.
    'enabled' => env('ADSENSE_ENABLED', true),

    // Fixed placements created in this site's AdSense account. Auto ads must
    // remain disabled in AdSense so Google cannot insert extra placements.
    'slots' => [
        'article_end' => env('ADSENSE_ARTICLE_END_SLOT', '2872372580'),
        'sidebar' => env('ADSENSE_SIDEBAR_SLOT', '3189348618'),
    ],
];
