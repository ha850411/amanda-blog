<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use RuntimeException;

class LineScheduleImageService
{
    private const CANVAS_WIDTH = 1440;

    private const CACHE_VERSION = 12;

    private const CARD_HEIGHT = 180;

    private const CARD_GAP = 18;

    private const CARDS_TOP = 140;

    private const CANVAS_BOTTOM_PADDING = 34;

    /** @var array<int, int> */
    private const IMAGE_WIDTHS = [700, 1440];

    private const GAME_THEMES = [
        'cs' => [
            'accent' => '#f59e0b',
            'badge_bg' => '#2e1b06',
            'badge_text' => '#fde68a',
            'label' => 'CS2',
        ],
        'valorant' => [
            'accent' => '#ff4655',
            'badge_bg' => '#300a14',
            'badge_text' => '#fecdd3',
            'label' => 'VAL',
        ],
        'lol' => [
            'accent' => '#0ea5e9',
            'badge_bg' => '#052438',
            'badge_text' => '#bae6fd',
            'label' => 'LoL',
        ],
        'default' => [
            'accent' => '#3b82f6',
            'badge_bg' => '#172554',
            'badge_text' => '#bfdbfe',
            'label' => 'MATCH',
        ],
    ];

    /**
     * Generate the image resolutions used by LINE and return their base URL.
     *
     * @param  array{
     *     title: string,
     *     subtitle: string,
     *     game?: ?string,
     *     matches: array<int, array{
     *         start_time: string,
     *         format: string,
     *         team1: string,
     *         team2: string,
     *         tournament: string,
     *         game?: ?string,
     *         odds: ?array{
     *             team1: array{price: float, bookmaker: string},
     *             team2: array{price: float, bookmaker: string}
     *         },
     *         h2h?: ?array{
     *             sample_size: int,
     *             history_total: int,
     *             team1_wins: int,
     *             team2_wins: int,
     *             team1_games: int,
     *             team2_games: int,
     *             series?: array<int, array{
     *                 date: string,
     *                 format: string,
     *                 team1_score: int,
     *                 team2_score: int,
     *                 winner: 'team1'|'team2'
     *             }>
     *         }
     *     }>
     * }  $data
     */
    public function create(array $data, string $linkUrl): string
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('The Imagick extension is required for LINE schedule images.');
        }

        $disk = Storage::disk((string) config('services.line.schedule_image_disk', 's3'));
        $hash = hash('sha256', json_encode([self::CACHE_VERSION, $data, $linkUrl], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $directory = 'line-schedules/'.$hash;

        if (! $disk->exists($directory.'/1440')) {
            $image = $this->render($data);

            foreach (self::IMAGE_WIDTHS as $width) {
                $variant = clone $image;

                if ($width !== self::CANVAS_WIDTH) {
                    $height = (int) round($variant->getImageHeight() * ($width / self::CANVAS_WIDTH));
                    $variant->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
                }

                $variant->setImageFormat('png');
                $variant->setImageCompression(Imagick::COMPRESSION_ZIP);
                $variant->setImageCompressionQuality(88);
                $this->store($disk, $directory.'/'.$width, $variant->getImagesBlob());
                $variant->clear();
            }

            $image->clear();
        }

        return rtrim($disk->url($directory), '/');
    }

    /**
     * @param  array{title: string, subtitle: string, game?: ?string, matches: array<int, array<string, mixed>>}  $data
     */
    private function render(array $data): Imagick
    {
        $matches = $data['matches'];
        $canvasHeight = $this->canvasHeight($matches);
        $image = new Imagick;
        $image->newImage(self::CANVAS_WIDTH, $canvasHeight, '#090d16', 'png');
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);

        $font = $this->fontPath();
        $draw = new ImagickDraw;
        $draw->setFont($font);
        $draw->setTextAntialias(true);

        // Header Title
        $draw->setFillColor('#f8fafc');
        $draw->setFontSize(40);
        $draw->setFontWeight(700);
        $draw->annotation(46, 64, $this->fitText($image, $draw, $data['title'], 1120, 32));

        // Header Subtitle
        $draw->setFillColor('#94a3b8');
        $draw->setFontSize(21);
        $draw->setFontWeight(400);
        $draw->annotation(48, 104, $this->fitText($image, $draw, $data['subtitle'], 1120, 17));

        // Top Right Count Badge
        $count = count($matches);
        $headerTheme = $this->themeForGame($data['game'] ?? null);
        $draw->setFillColor($headerTheme['accent']);
        $draw->roundRectangle(1252, 36, 1394, 92, 28, 28);
        $draw->setFillColor('#ffffff');
        $draw->setFontSize(22);
        $draw->setFontWeight(700);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation(1323, 72, $count.' 場');
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

        $mainWidth = 944;
        $cardWidth = 1352;
        $left = 44;
        $iconsToDraw = [];

        $y = self::CARDS_TOP;

        foreach ($matches as $match) {
            $x = $left;
            $matchGame = $match['game'] ?? $data['game'] ?? null;
            $theme = $this->themeForGame($matchGame);

            // Card Container Background & Border
            $draw->setFillColor('#131b2e');
            $draw->setStrokeColor('#1f2d47');
            $draw->setStrokeWidth(1.5);
            $draw->roundRectangle($x, $y, $x + $cardWidth, $y + self::CARD_HEIGHT, 16, 16);

            // Left Theme Indicator Bar
            $draw->setFillColor($theme['accent']);
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->roundRectangle($x + 2, $y + 14, $x + 6, $y + self::CARD_HEIGHT - 14, 2, 2);

            // Card Top Row: Game Icon / Badge / Time / Format / Tournament
            $iconPath = $this->iconPathForGame($matchGame);
            $timeX = $x + 24;

            if ($iconPath !== null) {
                $iconSize = 28;
                $iconsToDraw[] = [
                    'path' => $iconPath,
                    'x' => $x + 22,
                    'y' => $y + 13,
                    'size' => $iconSize,
                ];
                $timeX = $x + 22 + $iconSize + 12;
            } elseif ($matchGame !== null) {
                $badgeWidth = 52;
                $draw->setFillColor($theme['badge_bg']);
                $draw->setStrokeColor($theme['accent']);
                $draw->setStrokeWidth(1);
                $draw->roundRectangle($x + 22, $y + 15, $x + 22 + $badgeWidth, $y + 39, 6, 6);

                $draw->setFillColor($theme['badge_text']);
                $draw->setStrokeColor('none');
                $draw->setStrokeWidth(0);
                $draw->setFontSize(13);
                $draw->setFontWeight(700);
                $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                $draw->annotation($x + 22 + (int) round($badgeWidth / 2), $y + 32, $theme['label']);
                $draw->setTextAlignment(Imagick::ALIGN_LEFT);

                $timeX = $x + 22 + $badgeWidth + 12;
            }

            // Start Time
            $draw->setFillColor('#f8fafc');
            $draw->setFontSize(21);
            $draw->setFontWeight(700);
            $draw->annotation($timeX, $y + 34, $match['start_time']);

            $timeMetrics = $image->queryFontMetrics($draw, $match['start_time']);
            $formatX = $timeX + (int) round($timeMetrics['textWidth']) + 8;

            // Format (e.g. · BO3)
            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(17);
            $draw->setFontWeight(400);
            $draw->annotation($formatX, $y + 34, '· '.$match['format']);

            // Tournament Name (Top Right)
            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(16);
            $draw->setFontWeight(400);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $tournamentText = $this->fitText($image, $draw, (string) $match['tournament'], 440, 13);
            $draw->annotation($x + $mainWidth - 22, $y + 34, $tournamentText);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Card Middle Row: Symmetrical Team 1 & Team 2 Boxes
            $boxY = $y + 50;
            $boxHeight = 74;
            $boxWidth = 414;

            // Team 1 Box (Left)
            $draw->setFillColor('#1a243b');
            $draw->setStrokeColor('#293852');
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle($x + 22, $boxY, $x + 22 + $boxWidth, $boxY + $boxHeight, 10, 10);

            // Team 1 Name
            $draw->setFillColor('#f8fafc');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(20);
            $draw->setFontWeight(700);
            $draw->annotation($x + 38, $boxY + 45, $this->fitText($image, $draw, $match['team1'], 270, 14));

            // Team 1 Odds Pill
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x + 328, $boxY + 14, $x + 426, $boxY + 60, 8, 8);

            $hasOdds = ($match['odds'] ?? null) !== null;
            $team1Odds = $hasOdds ? sprintf('%.2f', $match['odds']['team1']['price']) : '—';
            $draw->setFillColor($hasOdds ? '#38bdf8' : '#64748b');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(18);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + 377, $boxY + 44, $team1Odds);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Center VS Badge
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#293852');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x + 452, $boxY + 17, $x + 492, $boxY + 57, 20, 20);

            $draw->setFillColor('#94a3b8');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(14);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + 472, $boxY + 42, 'VS');
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Team 2 Box (Right)
            $team2BoxX = $x + 508;
            $draw->setFillColor('#1a243b');
            $draw->setStrokeColor('#293852');
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle($team2BoxX, $boxY, $team2BoxX + $boxWidth, $boxY + $boxHeight, 10, 10);

            // Team 2 Name
            $draw->setFillColor('#f8fafc');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(20);
            $draw->setFontWeight(700);
            $draw->annotation($team2BoxX + 16, $boxY + 45, $this->fitText($image, $draw, $match['team2'], 270, 14));

            // Team 2 Odds Pill
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($team2BoxX + 306, $boxY + 14, $team2BoxX + 404, $boxY + 60, 8, 8);

            $team2Odds = $hasOdds ? sprintf('%.2f', $match['odds']['team2']['price']) : '—';
            $draw->setFillColor($hasOdds ? '#38bdf8' : '#64748b');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(18);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($team2BoxX + 355, $boxY + 44, $team2Odds);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Card Bottom Row: Odds / Bookmaker Source
            $statusText = $hasOdds
                ? ('獨贏盤口 · 來源：'.(string) $match['odds']['team1']['bookmaker'])
                : '獨贏盤口 · 暫無盤口';
            $draw->setFillColor('#64748b');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(14);
            $draw->setFontWeight(400);
            $draw->annotation($x + 24, $y + 154, $statusText);

            // Vertical separator line
            $draw->setStrokeColor('#263650');
            $draw->setStrokeWidth(1);
            $draw->line($x + $mainWidth, $y + 14, $x + $mainWidth, $y + self::CARD_HEIGHT - 14);

            $this->drawH2hPanel(
                $image,
                $draw,
                $x + $mainWidth + 16,
                $y,
                $cardWidth - $mainWidth - 32,
                $match,
                $theme,
            );

            $y += self::CARD_HEIGHT + self::CARD_GAP;
        }

        $image->drawImage($draw);

        foreach ($iconsToDraw as $iconData) {
            try {
                $icon = new Imagick($iconData['path']);
                $icon->resizeImage($iconData['size'], $iconData['size'], Imagick::FILTER_LANCZOS, 1);
                $image->compositeImage($icon, Imagick::COMPOSITE_OVER, $iconData['x'], $iconData['y']);
                $icon->clear();
            } catch (\Throwable) {
                // If icon cannot be loaded, gracefully continue.
            }
        }

        $image->stripImage();

        return $image;
    }

    /** @param array<int, array<string, mixed>> $matches */
    private function canvasHeight(array $matches): int
    {
        return self::CARDS_TOP
            + (count($matches) * self::CARD_HEIGHT)
            + (max(0, count($matches) - 1) * self::CARD_GAP)
            + self::CANVAS_BOTTOM_PADDING;
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array{accent: string, badge_bg: string, badge_text: string, label: string}  $theme
     */
    private function drawH2hPanel(
        Imagick $image,
        ImagickDraw $draw,
        int $x,
        int $y,
        int $width,
        array $match,
        array $theme,
    ): void {
        $h2h = $match['h2h'] ?? null;

        $draw->setFillColor('#e2e8f0');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFontSize(14);
        $draw->setFontWeight(700);
        $draw->annotation($x, $y + 29, '歷史交手');

        if (! is_array($h2h)) {
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x, $y + 45, $x + $width, $y + 160, 8, 8);

            $draw->setFillColor('#64748b');
            $draw->setStrokeColor('none');
            $draw->setFontSize(13);
            $draw->setFontWeight(500);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + (int) round($width / 2), $y + 108, '無近期交手紀錄');
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            return;
        }

        // Sample size pill badge
        $draw->setFillColor('#1e293b');
        $draw->setStrokeColor('#334155');
        $draw->setStrokeWidth(1);
        $draw->roundRectangle($x + 64, $y + 14, $x + 114, $y + 33, 4, 4);
        $draw->setFillColor('#94a3b8');
        $draw->setStrokeColor('none');
        $draw->setFontSize(11);
        $draw->setFontWeight(600);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($x + 89, $y + 28, '近'.$h2h['sample_size'].'場');
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

        $t1Wins = (int) $h2h['team1_wins'];
        $t2Wins = (int) $h2h['team2_wins'];
        $t1Games = (int) $h2h['team1_games'];
        $t2Games = (int) $h2h['team2_games'];

        $summary = sprintf(
            '%d勝 – %d勝 · 小局 %d:%d',
            $t1Wins,
            $t2Wins,
            $t1Games,
            $t2Games,
        );
        $draw->setFillColor($t1Wins > $t2Wins || $t2Wins > $t1Wins ? '#38bdf8' : '#cbd5e1');
        $draw->setFontSize(12);
        $draw->setFontWeight(600);
        $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
        $draw->annotation($x + $width, $y + 28, $summary);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

        $series = array_slice(is_array($h2h['series'] ?? null) ? $h2h['series'] : [], 0, 5);

        if ($series === []) {
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x, $y + 45, $x + $width, $y + 160, 8, 8);

            $draw->setFillColor('#64748b');
            $draw->setStrokeColor('none');
            $draw->setFontSize(13);
            $draw->setFontWeight(500);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + (int) round($width / 2), $y + 108, '無近期交手紀錄');
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            return;
        }

        $winColor = '#4ade80';
        $loseColor = '#f87171';
        $centerX = $x + 242;

        foreach ($series as $index => $result) {
            $rowY = $y + 40 + ($index * 25);
            $team1Won = ($result['winner'] ?? null) === 'team1';

            if ($index % 2 === 0) {
                $draw->setFillColor('#0f172a');
                $draw->setStrokeColor('none');
                $draw->roundRectangle($x - 4, $rowY, $x + $width + 4, $rowY + 22, 4, 4);
            }

            // 1. Date with Western Year (e.g. 2026/08/01)
            $draw->setFillColor('#94a3b8');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(11);
            $draw->setFontWeight(500);
            $draw->annotation($x, $rowY + 16, (string) ($result['date'] ?? '—'));

            // 2. Format Badge (e.g. BO3 / BO5)
            $draw->setFillColor('#1e293b');
            $draw->setStrokeColor('#334155');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x + 72, $rowY + 2, $x + 104, $rowY + 20, 3, 3);
            $draw->setFillColor('#cbd5e1');
            $draw->setStrokeColor('none');
            $draw->setFontSize(10);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + 88, $rowY + 15, (string) ($result['format'] ?? 'BO?'));
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // 3. Team 1 Name (Right-aligned to score pill, Green if won, Red if lost)
            $draw->setFillColor($team1Won ? $winColor : $loseColor);
            $draw->setFontSize(12);
            $draw->setFontWeight($team1Won ? 700 : 500);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $team1Text = $this->fitText($image, $draw, (string) $match['team1'], 96, 9);
            $draw->annotation($centerX - 36, $rowY + 16, $team1Text);

            // 4. Team 1 Score Pill (Green badge if won, Red badge if lost)
            $draw->setFillColor($team1Won ? '#052e16' : '#270e11');
            $draw->setStrokeColor($team1Won ? '#22c55e' : '#7f1d1d');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($centerX - 30, $rowY + 2, $centerX - 8, $rowY + 20, 4, 4);
            $draw->setFillColor($team1Won ? $winColor : $loseColor);
            $draw->setStrokeColor('none');
            $draw->setFontSize(11);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($centerX - 19, $rowY + 15, (string) ($result['team1_score'] ?? 0));

            // 5. Separator
            $draw->setFillColor('#475569');
            $draw->setFontSize(11);
            $draw->setFontWeight(400);
            $draw->annotation($centerX, $rowY + 15, '-');

            // 6. Team 2 Score Pill (Green badge if won, Red badge if lost)
            $draw->setFillColor(! $team1Won ? '#052e16' : '#270e11');
            $draw->setStrokeColor(! $team1Won ? '#22c55e' : '#7f1d1d');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($centerX + 8, $rowY + 2, $centerX + 30, $rowY + 20, 4, 4);
            $draw->setFillColor(! $team1Won ? $winColor : $loseColor);
            $draw->setStrokeColor('none');
            $draw->setFontSize(11);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($centerX + 19, $rowY + 15, (string) ($result['team2_score'] ?? 0));

            // 7. Team 2 Name (Left-aligned to score pill, Green if won, Red if lost)
            $draw->setFillColor(! $team1Won ? $winColor : $loseColor);
            $draw->setFontSize(12);
            $draw->setFontWeight(! $team1Won ? 700 : 500);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);
            $team2Text = $this->fitText($image, $draw, (string) $match['team2'], 96, 9);
            $draw->annotation($centerX + 36, $rowY + 16, $team2Text);
        }
    }

    public function teamAbbreviation(string $name): string
    {
        $known = [
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

        $normalized = mb_strtolower(trim($name));
        if (isset($known[$normalized])) {
            return $known[$normalized];
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

    private function fitText(Imagick $image, ImagickDraw $draw, string $text, int $maxWidth, int $minimumFontSize): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        $fontSize = (int) $draw->getFontSize();
        $truncated = false;

        while ($fontSize > $minimumFontSize && $image->queryFontMetrics($draw, $text)['textWidth'] > $maxWidth) {
            $fontSize--;
            $draw->setFontSize($fontSize);
        }

        while ($text !== '' && $image->queryFontMetrics($draw, $text)['textWidth'] > $maxWidth) {
            $text = mb_substr($text, 0, -1);
            $truncated = true;
        }

        if (! $truncated) {
            return $text;
        }

        while ($text !== '' && $image->queryFontMetrics($draw, $text.'…')['textWidth'] > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    private function iconPathForGame(?string $game): ?string
    {
        if ($game === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($game));
        $aliases = [
            'cs' => 'cs',
            'cs2' => 'cs',
            'valorant' => 'valorant',
            'val' => 'valorant',
            'lol' => 'lol',
        ];

        $key = $aliases[$normalized] ?? null;

        if ($key === null) {
            return null;
        }

        $candidates = [
            public_path("images/games/{$key}.png"),
            resource_path("images/games/{$key}.png"),
            base_path("public/images/games/{$key}.png"),
            base_path("resources/images/games/{$key}.png"),
            __DIR__."/../../public/images/games/{$key}.png",
            __DIR__."/../../resources/images/games/{$key}.png",
        ];

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{accent: string, badge_bg: string, badge_text: string, label: string}
     */
    private function themeForGame(?string $game): array
    {
        $normalized = mb_strtolower(trim((string) $game));

        return self::GAME_THEMES[$normalized] ?? self::GAME_THEMES['default'];
    }

    private function fontPath(): string
    {
        $configured = (string) config('services.line.schedule_image_font');
        $candidates = array_filter([
            $configured,
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKtc-Regular.otf',
            '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ]);

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('A Traditional Chinese font is required for LINE schedule images.');
    }

    private function store(FilesystemAdapter $disk, string $path, string $contents): void
    {
        $stored = $disk->put($path, $contents, [
            'visibility' => 'public',
            'ContentType' => 'image/png',
            'CacheControl' => 'public, max-age=604800, immutable',
        ]);

        if (! $stored) {
            throw new RuntimeException("Unable to store LINE schedule image: {$path}");
        }
    }
}
