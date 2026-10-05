<?php

namespace App\Services\LineSchedule\Support;

class TeamAbbreviation
{
    /** @var array<string, string> */
    private const KNOWN = [
        'anyone\'s legend' => 'AL',
        'bilibili gaming' => 'BLG',
        'top esports' => 'TES',
        'weibo gaming' => 'WBG',
        'funplus phoenix' => 'FPX',
        'edward gaming' => 'EDG',
        'royal never give up' => 'RNG',
        'invictus gaming' => 'IG',
        'jd gaming' => 'JDG',
        'ninjas in pyjamas' => 'NIP',
        'thundertalk gaming' => 'TT',
        'ultra prime' => 'UP',
        'rare atom' => 'RA',
        'team we' => 'WE',
        'oh my god' => 'OMG',
        'lng esports' => 'LNG',
        'gen.g' => 'GEN',
        't1' => 'T1',
        'dplus kia' => 'DK',
        'kt rolster' => 'KT',
        'hanwha life esports' => 'HLE',
        'drx' => 'DRX',
        'natus vincere' => 'NAVI',
        'faze clan' => 'FaZe',
        'g2 esports' => 'G2',
        'team vitality' => 'VIT',
        'team liquid' => 'TL',
        'fnatic' => 'FNC',
        'sentinels' => 'SEN',
        'paper rex' => 'PRX',
        'evil geniuses' => 'EG',
        'cloud9' => 'C9',
        '100 thieves' => '100T',
        'nongshim redforce' => 'NS',
        'brion' => 'BRO',
        'fredit brion' => 'BRO',
        'oksavingsbank brion' => 'BRO',
        'kwangdong freecs' => 'KDF',
        'fearx' => 'FOX',
        'bnk fearx' => 'FOX',
        't1 esports' => 'T1',
        'psg talon' => 'PSG',
        'flyquest' => 'FLY',
        'team secret' => 'TS',
        'team heretics' => 'TH',
        'karmine corp' => 'KC',
        'mad lions koi' => 'MDK',
        'giantx' => 'GX',
        'rogue' => 'RGE',
        'sk gaming' => 'SK',
        'team bds' => 'BDS',
    ];

    public static function get(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        if (isset(self::KNOWN[$normalized])) {
            return self::KNOWN[$normalized];
        }

        if (mb_strlen($name) <= 4) {
            return mb_strtoupper($name);
        }

        $words = preg_split('/\s+/', trim($name));
        if ($words !== false && count($words) >= 2 && count($words) <= 4) {
            $initials = '';
            foreach ($words as $word) {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            }
            if (mb_strlen($initials) >= 2 && mb_strlen($initials) <= 4) {
                return $initials;
            }
        }

        return mb_substr($name, 0, 4);
    }
}
