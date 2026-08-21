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

    private const CACHE_VERSION = 19;

    private const CARD_HEIGHT = 180;

    private const CARD_GAP = 18;

    private const CARDS_TOP = 140;

    private const CANVAS_BOTTOM_PADDING = 34;

    /** @var array<int, int> */
    private const IMAGE_WIDTHS = [700, 1440];

    private const FORMAT_THEMES = [
        1 => [
            'bg' => '#082f49',
            'border' => '#0284c7',
            'text' => '#38bdf8',
        ],
        2 => [
            'bg' => '#042f2e',
            'border' => '#0d9488',
            'text' => '#2dd4bf',
        ],
        3 => [
            'bg' => '#451a03',
            'border' => '#f59e0b',
            'text' => '#fde68a',
        ],
        5 => [
            'bg' => '#3b0764',
            'border' => '#c084fc',
            'text' => '#f5d0fe',
        ],
        7 => [
            'bg' => '#4c0519',
            'border' => '#f43f5e',
            'text' => '#fecdd3',
        ],
        'default' => [
            'bg' => '#1e293b',
            'border' => '#475569',
            'text' => '#94a3b8',
        ],
    ];

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
     *         is_live?: bool,
     *         series_score?: ?string,
     *         score?: ?string,
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
            $timeWidth = (int) round($timeMetrics['textWidth']);

            // Format Badge (e.g. BO1 / BO2 / BO3 / BO5)
            $formatTheme = $this->formatTheme($match['format'] ?? null);
            $formatBadgeX = $timeX + $timeWidth + 12;
            $formatBadgeWidth = 56;
            $formatBadgeHeight = 24;
            $formatBadgeY = $y + 14;

            $draw->setFillColor($formatTheme['bg']);
            $draw->setStrokeColor($formatTheme['border']);
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle(
                $formatBadgeX,
                $formatBadgeY,
                $formatBadgeX + $formatBadgeWidth,
                $formatBadgeY + $formatBadgeHeight,
                6,
                6
            );

            $draw->setFillColor($formatTheme['text']);
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(13);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation(
                $formatBadgeX + (int) round($formatBadgeWidth / 2),
                $formatBadgeY + 17,
                $formatTheme['label']
            );
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            if ($match['is_live'] ?? false) {
                $liveBadgeX = $formatBadgeX + $formatBadgeWidth + 8;
                $liveBadgeWidth = 54;

                $draw->setFillColor('#450a0a');
                $draw->setStrokeColor('#ef4444');
                $draw->setStrokeWidth(1.2);
                $draw->roundRectangle(
                    $liveBadgeX,
                    $formatBadgeY,
                    $liveBadgeX + $liveBadgeWidth,
                    $formatBadgeY + $formatBadgeHeight,
                    6,
                    6,
                );

                $draw->setFillColor('#fca5a5');
                $draw->setStrokeColor('none');
                $draw->setStrokeWidth(0);
                $draw->setFontSize(12);
                $draw->setFontWeight(700);
                $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                $draw->annotation(
                    $liveBadgeX + (int) round($liveBadgeWidth / 2),
                    $formatBadgeY + 17,
                    '滾球',
                );
                $draw->setTextAlignment(Imagick::ALIGN_LEFT);
            }

            // Tournament Name (Top Right)
            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(16);
            $draw->setFontWeight(400);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $tournamentText = $this->fitText($image, $draw, (string) $match['tournament'], 440, 13);
            $draw->annotation($x + $mainWidth - 22, $y + 34, $tournamentText);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Card Middle Row: Symmetrical Team 1 & Team 2 Boxes (Spacious Layout)
            $boxY = $y + 48;
            $boxHeight = 74;
            $boxWidth = 396;

            // Team 1 Box (Left)
            $draw->setFillColor('#1a243b');
            $draw->setStrokeColor('#293852');
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle($x + 22, $boxY, $x + 22 + $boxWidth, $boxY + $boxHeight, 10, 10);

            // Team 1 Name
            $draw->setFillColor('#f8fafc');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(19);
            $draw->setFontWeight(700);
            $draw->annotation($x + 36, $boxY + 45, $this->fitText($image, $draw, $match['team1'], 260, 14));

            // Team 1 Odds Pill
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x + 318, $boxY + 14, $x + 406, $boxY + 60, 8, 8);

            $hasOdds = ($match['odds'] ?? null) !== null;
            $team1Odds = $hasOdds ? sprintf('%.2f', $match['odds']['team1']['price']) : '—';
            $draw->setFillColor($hasOdds ? '#38bdf8' : '#64748b');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(17);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + 362, $boxY + 44, $team1Odds);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Center VS Badge / Live Match Scoreboard Hub
            $this->drawCenterMatchBadge($draw, $x, $boxY, $match);

            // Team 2 Box (Right)
            $team2BoxX = $x + 526;
            $draw->setFillColor('#1a243b');
            $draw->setStrokeColor('#293852');
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle($team2BoxX, $boxY, $team2BoxX + $boxWidth, $boxY + $boxHeight, 10, 10);

            // Team 2 Name
            $draw->setFillColor('#f8fafc');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(19);
            $draw->setFontWeight(700);
            $draw->annotation($team2BoxX + 16, $boxY + 45, $this->fitText($image, $draw, $match['team2'], 260, 14));

            // Team 2 Odds Pill
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($team2BoxX + 296, $boxY + 14, $team2BoxX + 384, $boxY + 60, 8, 8);

            $team2Odds = $hasOdds ? sprintf('%.2f', $match['odds']['team2']['price']) : '—';
            $draw->setFillColor($hasOdds ? '#38bdf8' : '#64748b');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(17);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($team2BoxX + 340, $boxY + 44, $team2Odds);
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
        $draw->setFontSize(13);
        $draw->setFontWeight(700);
        $draw->annotation($x, $y + 25, '歷史交手');

        if (! is_array($h2h)) {
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x, $y + 42, $x + $width, $y + 162, 8, 8);

            $draw->setFillColor('#64748b');
            $draw->setStrokeColor('none');
            $draw->setFontSize(13);
            $draw->setFontWeight(500);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + (int) round($width / 2), $y + 107, '無近期交手紀錄');
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            return;
        }

        // Sample size pill badge
        $draw->setFillColor('#1e293b');
        $draw->setStrokeColor('#334155');
        $draw->setStrokeWidth(1);
        $draw->roundRectangle($x + 58, $y + 11, $x + 104, $y + 28, 4, 4);
        $draw->setFillColor('#94a3b8');
        $draw->setStrokeColor('none');
        $draw->setFontSize(10);
        $draw->setFontWeight(600);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($x + 81, $y + 23, '近'.$h2h['sample_size'].'場');
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

        $t1Wins = (int) $h2h['team1_wins'];
        $t2Wins = (int) $h2h['team2_wins'];
        $t1Games = (int) $h2h['team1_games'];
        $t2Games = (int) $h2h['team2_games'];
        $totalWins = $t1Wins + $t2Wins;

        // Calculate Win Rate & Ratio
        if ($totalWins > 0) {
            $t1Ratio = $t1Wins / $totalWins;
            $t1Percent = (int) round($t1Ratio * 100);
            $t2Percent = 100 - $t1Percent;
        } elseif ($t1Games + $t2Games > 0) {
            $t1Ratio = $t1Games / ($t1Games + $t2Games);
            $t1Percent = (int) round($t1Ratio * 100);
            $t2Percent = 100 - $t1Percent;
        } else {
            $t1Ratio = 0.5;
            $t1Percent = 50;
            $t2Percent = 50;
        }
        $t2Ratio = 1.0 - $t1Ratio;

        $winGreen = '#4ade80';
        $loseRed = '#f87171';
        $neutralBlue = '#38bdf8';

        // Colored Win Rates Summary in Header
        $t1TextColor = $t1Wins > $t2Wins ? $winGreen : ($t1Wins < $t2Wins ? $loseRed : $neutralBlue);
        $t2TextColor = $t2Wins > $t1Wins ? $winGreen : ($t2Wins < $t1Wins ? $loseRed : $neutralBlue);

        $t2Summary = sprintf('%d勝 (%d%%)', $t2Wins, $t2Percent);
        $draw->setFillColor($t2TextColor);
        $draw->setFontSize(12);
        $draw->setFontWeight($t2Wins >= $t1Wins ? 700 : 500);
        $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
        $draw->annotation($x + $width, $y + 25, $t2Summary);

        $metrics = $image->queryFontMetrics($draw, $t2Summary);
        $t2Width = (int) round($metrics['textWidth']);
        $sepX = $x + $width - $t2Width - 10;

        $draw->setFillColor('#64748b');
        $draw->setFontSize(11);
        $draw->setFontWeight(400);
        $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
        $draw->annotation($sepX, $y + 25, '-');

        $t1Summary = sprintf('%d勝 (%d%%)', $t1Wins, $t1Percent);
        $draw->setFillColor($t1TextColor);
        $draw->setFontSize(12);
        $draw->setFontWeight($t1Wins >= $t2Wins ? 700 : 500);
        $draw->annotation($sepX - 10, $y + 25, $t1Summary);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

        // Win Rate Progress Bar
        $barY = $y + 33;
        $barHeight = 6;
        $barWidth = $width;

        $draw->setFillColor('#1e293b');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->roundRectangle($x, $barY, $x + $barWidth, $barY + $barHeight, 3, 3);

        $barGreen = '#22c55e';
        $barRed = '#ef4444';
        $barBlue = '#38bdf8';

        $t1BarColor = $t1Wins > $t2Wins ? $barGreen : ($t1Wins < $t2Wins ? $barRed : $barBlue);
        $t2BarColor = $t2Wins > $t1Wins ? $barGreen : ($t2Wins < $t1Wins ? $barRed : $barBlue);

        if ($t1Ratio >= 1.0) {
            $draw->setFillColor($t1BarColor);
            $draw->roundRectangle($x, $barY, $x + $barWidth, $barY + $barHeight, 3, 3);
        } elseif ($t2Ratio >= 1.0) {
            $draw->setFillColor($t2BarColor);
            $draw->roundRectangle($x, $barY, $x + $barWidth, $barY + $barHeight, 3, 3);
        } else {
            $t1W = (int) round($barWidth * $t1Ratio);
            $t1W = max(8, min($barWidth - 8, $t1W));

            $draw->setFillColor($t1BarColor);
            $draw->roundRectangle($x, $barY, $x + $t1W - 1, $barY + $barHeight, 3, 3);

            $draw->setFillColor($t2BarColor);
            $draw->roundRectangle($x + $t1W + 1, $barY, $x + $barWidth, $barY + $barHeight, 3, 3);
        }

        // Recent Series List
        $series = array_slice(is_array($h2h['series'] ?? null) ? $h2h['series'] : [], 0, 5);

        if ($series === []) {
            return;
        }

        $centerX = $x + 240;

        foreach ($series as $index => $result) {
            $rowY = $y + 45 + ($index * 24);
            $team1Won = ($result['winner'] ?? null) === 'team1';

            if ($index % 2 === 0) {
                $draw->setFillColor('#0f172a');
                $draw->setStrokeColor('none');
                $draw->roundRectangle($x - 4, $rowY, $x + $width + 4, $rowY + 21, 4, 4);
            }

            // 1. Date with Western Year (e.g. 2026/08/01)
            $draw->setFillColor('#94a3b8');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(10.5);
            $draw->setFontWeight(500);
            $draw->annotation($x, $rowY + 15, (string) ($result['date'] ?? '—'));

            // 2. Format Badge (e.g. BO1 / BO2 / BO3 / BO5)
            $formatTheme = $this->formatTheme($result['format'] ?? null);
            $draw->setFillColor($formatTheme['bg']);
            $draw->setStrokeColor($formatTheme['border']);
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x + 72, $rowY + 1, $x + 102, $rowY + 19, 3, 3);
            $draw->setFillColor($formatTheme['text']);
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(9.5);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + 87, $rowY + 14, $formatTheme['label']);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // 3. Team 1 Name (Right-aligned to score pill, Green if won, Red if lost)
            $draw->setFillColor($team1Won ? $winGreen : $loseRed);
            $draw->setFontSize(11.5);
            $draw->setFontWeight($team1Won ? 700 : 500);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $team1Text = $this->fitText($image, $draw, (string) $match['team1'], 94, 9);
            $draw->annotation($centerX - 35, $rowY + 15, $team1Text);

            // 4. Team 1 Score Pill (Green badge if won, Red badge if lost)
            $draw->setFillColor($team1Won ? '#052e16' : '#270e11');
            $draw->setStrokeColor($team1Won ? '#22c55e' : '#7f1d1d');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($centerX - 29, $rowY + 1, $centerX - 8, $rowY + 19, 3, 3);
            $draw->setFillColor($team1Won ? $winGreen : $loseRed);
            $draw->setStrokeColor('none');
            $draw->setFontSize(10.5);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($centerX - 18, $rowY + 14, (string) ($result['team1_score'] ?? 0));

            // 5. Separator
            $draw->setFillColor('#475569');
            $draw->setFontSize(10.5);
            $draw->setFontWeight(400);
            $draw->annotation($centerX, $rowY + 14, '-');

            // 6. Team 2 Score Pill (Green badge if won, Red badge if lost)
            $draw->setFillColor(! $team1Won ? '#052e16' : '#270e11');
            $draw->setStrokeColor(! $team1Won ? '#22c55e' : '#7f1d1d');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($centerX + 8, $rowY + 1, $centerX + 29, $rowY + 19, 3, 3);
            $draw->setFillColor(! $team1Won ? $winGreen : $loseRed);
            $draw->setStrokeColor('none');
            $draw->setFontSize(10.5);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($centerX + 19, $rowY + 14, (string) ($result['team2_score'] ?? 0));

            // 7. Team 2 Name (Left-aligned to score pill, Green if won, Red if lost)
            $draw->setFillColor(! $team1Won ? $winGreen : $loseRed);
            $draw->setFontSize(11.5);
            $draw->setFontWeight(! $team1Won ? 700 : 500);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);
            $team2Text = $this->fitText($image, $draw, (string) $match['team2'], 94, 9);
            $draw->annotation($centerX + 35, $rowY + 15, $team2Text);
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

    /**
     * @return array{bg: string, border: string, text: string, label: string}
     */
    public function formatTheme(mixed $format): array
    {
        $raw = trim((string) $format);
        $text = mb_strtoupper($raw);
        preg_match('/(?:BO)?(\d+)/i', $text, $matches);
        $number = isset($matches[1]) ? (int) $matches[1] : null;

        $theme = self::FORMAT_THEMES[$number] ?? self::FORMAT_THEMES['default'];

        return [
            'bg' => $theme['bg'],
            'border' => $theme['border'],
            'text' => $theme['text'],
            'label' => $text !== '' ? $text : 'BO?',
        ];
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

    /**
     * @param  array<string, mixed>  $match
     */
    private function drawCenterMatchBadge(
        ImagickDraw $draw,
        int $x,
        int $boxY,
        array $match,
    ): void {
        $isLive = (bool) ($match['is_live'] ?? false);
        $seriesScore = is_string($match['series_score'] ?? null) && trim($match['series_score']) !== ''
            ? trim($match['series_score'])
            : null;
        $mapScore = is_string($match['score'] ?? null) && trim($match['score']) !== ''
            ? trim($match['score'])
            : null;

        $centerX = $x + 472;

        if (! $isLive) {
            // Not live: Symmetrical rounded VS pill
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#293852');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($centerX - 22, $boxY + 16, $centerX + 22, $boxY + 58, 21, 21);

            $draw->setFillColor('#94a3b8');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(14);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($centerX, $boxY + 42, 'VS');
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            return;
        }

        // Live Match Hub Container (88px wide with 10px breathing margins)
        $hubLeft = $centerX - 44;
        $hubRight = $centerX + 44;
        $hubTop = $boxY + 5;
        $hubBottom = $boxY + 69;

        $draw->setFillColor('#220707');
        $draw->setStrokeColor('#dc2626');
        $draw->setStrokeWidth(1.2);
        $draw->roundRectangle($hubLeft, $hubTop, $hubRight, $hubBottom, 10, 10);

        // Parse series score digits if available
        $parsedSeries = $this->parseScorePair($seriesScore);

        if ($parsedSeries !== null) {
            [$team1Wins, $team2Wins] = $parsedSeries;
            $reqWins = $this->seriesWinSlots($match['format'] ?? null);

            // 1. Draw Map Win Indicator Pips above score digits
            $this->drawMapPips($draw, $centerX - 18, $boxY + 13, $team1Wins, $reqWins, '#38bdf8');
            $this->drawMapPips($draw, $centerX + 18, $boxY + 13, $team2Wins, $reqWins, '#fb7185');

            // 2. Scoreboard Digits (Series Score)
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(20);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);

            // Team 1 score digit
            $draw->setFillColor($team1Wins > $team2Wins ? '#ffffff' : ($team1Wins < $team2Wins ? '#94a3b8' : '#e2e8f0'));
            $draw->annotation($centerX - 18, $boxY + 34, (string) $team1Wins);

            // Colon separator
            $draw->setFillColor('#ef4444');
            $draw->setFontSize(14);
            $draw->annotation($centerX, $boxY + 33, ':');

            // Team 2 score digit
            $draw->setFontSize(20);
            $draw->setFillColor($team2Wins > $team1Wins ? '#ffffff' : ($team2Wins < $team1Wins ? '#94a3b8' : '#e2e8f0'));
            $draw->annotation($centerX + 18, $boxY + 34, (string) $team2Wins);

            // 3. Current-game score, or a series label while the next game
            // has not started. The BO win indicators stay visible in both
            // states so BO1 / BO3 / BO5 remain visually distinct.
            $hasCurrentGameScore = $mapScore !== null;
            $pillLeft = $centerX - ($hasCurrentGameScore ? 35 : 26);
            $pillRight = $centerX + ($hasCurrentGameScore ? 35 : 26);
            $pillTop = $boxY + 44;
            $pillBottom = $boxY + 62;

            $draw->setFillColor('#3d0d0d');
            $draw->setStrokeColor('#991b1b');
            $draw->setStrokeWidth(0.8);
            $draw->roundRectangle($pillLeft, $pillTop, $pillRight, $pillBottom, 9, 9);

            // Live dot
            $draw->setFillColor('#ef4444');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $dotX = $centerX - ($hasCurrentGameScore ? 20 : 14);
            $draw->circle($dotX, $boxY + 53, $dotX + 2.5, $boxY + 53);

            // Current-game score text. Keep this distinct from the series
            // score above so LoL viewers can read both at a glance.
            $draw->setFillColor('#fca5a5');
            $draw->setFontSize(10);
            $draw->setFontWeight(700);
            $draw->annotation(
                $centerX + ($hasCurrentGameScore ? 7 : 5),
                $boxY + 57,
                $hasCurrentGameScore ? '局 '.$mapScore : 'SERIES',
            );
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            return;
        }

        // Single score fallback
        $primaryScore = $seriesScore ?? $mapScore;
        $scoreLabel = $seriesScore !== null ? 'SERIES' : 'LIVE';

        if ($primaryScore !== null) {
            $parsed = $this->parseScorePair($primaryScore);
            if ($parsed !== null) {
                [$s1, $s2] = $parsed;
                $draw->setStrokeColor('none');
                $draw->setStrokeWidth(0);
                $draw->setFontSize(20);
                $draw->setFontWeight(700);
                $draw->setTextAlignment(Imagick::ALIGN_CENTER);

                $draw->setFillColor($s1 > $s2 ? '#ffffff' : ($s1 < $s2 ? '#94a3b8' : '#e2e8f0'));
                $draw->annotation($centerX - 18, $boxY + 34, (string) $s1);

                $draw->setFillColor('#ef4444');
                $draw->setFontSize(14);
                $draw->annotation($centerX, $boxY + 33, ':');

                $draw->setFontSize(20);
                $draw->setFillColor($s2 > $s1 ? '#ffffff' : ($s2 < $s1 ? '#94a3b8' : '#e2e8f0'));
                $draw->annotation($centerX + 18, $boxY + 34, (string) $s2);
            } else {
                $draw->setFillColor('#ffffff');
                $draw->setStrokeColor('none');
                $draw->setFontSize(14);
                $draw->setFontWeight(700);
                $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                $draw->annotation($centerX, $boxY + 34, $primaryScore);
            }

            // Live tag pill
            $pillLeft = $centerX - 26;
            $pillRight = $centerX + 26;
            $pillTop = $boxY + 44;
            $pillBottom = $boxY + 62;

            $draw->setFillColor('#3d0d0d');
            $draw->setStrokeColor('#991b1b');
            $draw->setStrokeWidth(0.8);
            $draw->roundRectangle($pillLeft, $pillTop, $pillRight, $pillBottom, 9, 9);

            $draw->setFillColor('#ef4444');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->circle($centerX - 14, $boxY + 53, $centerX - 14 + 2, $boxY + 53);

            $draw->setFillColor('#fca5a5');
            $draw->setFontSize(10);
            $draw->setFontWeight(700);
            $draw->annotation($centerX + 5, $boxY + 57, $scoreLabel);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            return;
        }

        // Live with no scores
        $draw->setFillColor('#fca5a5');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFontSize(14);
        $draw->setFontWeight(700);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($centerX, $boxY + 42, 'LIVE');
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);
    }

    /** @return array{0: int, 1: int}|null */
    private function parseScorePair(?string $score): ?array
    {
        if (! is_string($score)) {
            return null;
        }

        if (preg_match('/(\d+)\s*[-:：]\s*(\d+)/u', $score, $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    public function seriesWinSlots(mixed $format): int
    {
        if (preg_match('/BO(\d+)/i', trim((string) $format), $matches) === 1) {
            $bo = (int) $matches[1];

            return max(1, intdiv($bo, 2) + 1);
        }

        return 2; // Default for BO3 or unknown
    }

    private function drawMapPips(
        ImagickDraw $draw,
        int $centerX,
        int $y,
        int $wins,
        int $totalRequired,
        string $wonColor,
    ): void {
        $pipWidth = 5;
        $pipHeight = 3;
        $gap = 2;
        $totalWidth = ($totalRequired * $pipWidth) + (($totalRequired - 1) * $gap);
        $startX = $centerX - (int) round($totalWidth / 2);

        for ($i = 0; $i < $totalRequired; $i++) {
            $left = $startX + ($i * ($pipWidth + $gap));
            $isWon = $i < min($wins, $totalRequired);

            $draw->setFillColor($isWon ? $wonColor : '#1e293b');
            $draw->setStrokeColor($isWon ? $wonColor : '#475569');
            $draw->setStrokeWidth(0.7);
            $draw->roundRectangle($left, $y, $left + $pipWidth, $y + $pipHeight, 1.5, 1.5);
        }
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
