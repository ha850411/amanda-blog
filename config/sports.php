<?php

return [
    // Sportsurge's schedule and player pages currently use this public feed.
    'api_url' => 'https://streamed.pk/api',
    'source_page' => 'https://raaj648.github.io/streampage2sportsurge/',
    'timezone' => 'Asia/Taipei',
    'cache_seconds' => 60,
    'stale_seconds' => 900,
    'timeout_seconds' => 8,
    'embed_hosts' => ['embed.st'],

    'categories' => [
        'baseball' => '棒球', 'basketball' => '籃球',
        'american-football' => '美式足球', 'football' => '足球',
        'hockey' => '冰球', 'fight' => '格鬥／摔角',
        'motor-sports' => '賽車', 'tennis' => '網球', 'golf' => '高爾夫',
        'rugby' => '橄欖球', 'cricket' => '板球', 'afl' => '澳式足球',
        'billiards' => '撞球', 'darts' => '飛鏢', 'other' => '其他',
    ],

    // The upstream has sports, but no reliable league field. Only classify
    // MLB/NBA when BOTH team names match; other games keep their sport category.
    'league_teams' => [
        'MLB' => [
            'Arizona Diamondbacks', 'Atlanta Braves', 'Baltimore Orioles',
            'Boston Red Sox', 'Chicago Cubs', 'Chicago White Sox', 'Cincinnati Reds',
            'Cleveland Guardians', 'Colorado Rockies', 'Detroit Tigers',
            'Houston Astros', 'Kansas City Royals', 'Los Angeles Angels',
            'Los Angeles Dodgers', 'Miami Marlins', 'Milwaukee Brewers',
            'Minnesota Twins', 'New York Mets', 'New York Yankees', 'Athletics',
            'Oakland Athletics', 'Sacramento Athletics', 'Philadelphia Phillies',
            'Pittsburgh Pirates', 'San Diego Padres', 'San Francisco Giants',
            'Seattle Mariners', 'St. Louis Cardinals', 'St Louis Cardinals',
            'Tampa Bay Rays', 'Texas Rangers', 'Toronto Blue Jays', 'Washington Nationals',
        ],
        'NBA' => [
            'Atlanta Hawks', 'Boston Celtics', 'Brooklyn Nets', 'Charlotte Hornets',
            'Chicago Bulls', 'Cleveland Cavaliers', 'Dallas Mavericks', 'Denver Nuggets',
            'Detroit Pistons', 'Golden State Warriors', 'Houston Rockets', 'Indiana Pacers',
            'Los Angeles Clippers', 'LA Clippers', 'Los Angeles Lakers', 'Memphis Grizzlies',
            'Miami Heat', 'Milwaukee Bucks', 'Minnesota Timberwolves', 'New Orleans Pelicans',
            'New York Knicks', 'Oklahoma City Thunder', 'Orlando Magic', 'Philadelphia 76ers',
            'Phoenix Suns', 'Portland Trail Blazers', 'Sacramento Kings', 'San Antonio Spurs',
            'Toronto Raptors', 'Utah Jazz', 'Washington Wizards',
        ],
    ],
];
