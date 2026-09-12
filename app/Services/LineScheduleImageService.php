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

    private const CACHE_VERSION = 28;

    private const CARD_HEIGHT = 180;

    private const CARD_GAP = 18;

    private const CARDS_TOP = 140;

    private const BET_CARDS_TOP = 150;

    private const BET_CARD_HEADER_HEIGHT = 158;

    private const LEG_BOX_HEIGHT = 146;

    private const LEG_GAP = 12;

    private const BET_CARD_BOTTOM_PAD = 12;

    private const BET_CARD_GAP = 20;

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
            'bg' => '#064e3b',
            'border' => '#059669',
            'text' => '#6ee7b7',
        ],
        5 => [
            'bg' => '#451a03',
            'border' => '#f59e0b',
            'text' => '#fde047',
        ],
        7 => [
            'bg' => '#3b0764',
            'border' => '#c084fc',
            'text' => '#f5d0fe',
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
     * @param  array<string, mixed>  $data
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
     * @param  array<string, mixed>  $data
     */
    private function render(array $data): Imagick
    {
        if (($data['type'] ?? '') === 'balance_chart') {
            return $this->renderBalanceChart($data);
        }

        if (($data['type'] ?? '') === 'bet_history') {
            return $this->renderBetHistory($data);
        }

        if (($data['type'] ?? '') === 'bets' || isset($data['bets'])) {
            return $this->renderBets($data);
        }

        return $this->renderSchedule($data);
    }

    /**
     * @param  array{title: string, subtitle: string, game?: ?string, matches: array<int, array<string, mixed>>}  $data
     */
    private function renderSchedule(array $data): Imagick
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
            $isBo5OrMore = in_array($formatTheme['label'], ['BO5', 'BO7'], true);
            $formatBadgeWidth = $isBo5OrMore ? 62 : 56;
            $formatBadgeHeight = 26;
            $formatBadgeY = $y + 13;

            $draw->setFillColor($formatTheme['bg']);
            $draw->setStrokeColor($formatTheme['border']);
            $draw->setStrokeWidth($isBo5OrMore ? 1.6 : 1.2);
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
            $draw->setFontSize(14);
            $draw->setFontWeight(800);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation(
                $formatBadgeX + (int) round($formatBadgeWidth / 2),
                $formatBadgeY + 18,
                $formatTheme['label']
            );
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            if ($match['is_live'] ?? false) {
                $liveBadgeX = $formatBadgeX + $formatBadgeWidth + 8;
                $liveBadgeWidth = 64;
                $liveBadgeHeight = 26;

                $draw->setFillColor('#3b0811');
                $draw->setStrokeColor('#ef4444');
                $draw->setStrokeWidth(1.2);
                $draw->roundRectangle(
                    $liveBadgeX,
                    $formatBadgeY,
                    $liveBadgeX + $liveBadgeWidth,
                    $formatBadgeY + $liveBadgeHeight,
                    6,
                    6,
                );

                // Live blinking red dot indicator
                $draw->setFillColor('#ef4444');
                $draw->setStrokeColor('none');
                $draw->setStrokeWidth(0);
                $draw->circle(
                    $liveBadgeX + 14,
                    $formatBadgeY + 13,
                    $liveBadgeX + 16.5,
                    $formatBadgeY + 13
                );

                $draw->setFillColor('#ffffff');
                $draw->setFontSize(13);
                $draw->setFontWeight(700);
                $draw->setTextAlignment(Imagick::ALIGN_LEFT);
                $draw->annotation(
                    $liveBadgeX + 23,
                    $formatBadgeY + 18,
                    '滾球',
                );
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
            $draw->setFontSize(21);
            $draw->setFontWeight(700);
            $draw->annotation($x + 36, $boxY + 46, $this->fitText($image, $draw, $match['team1'], 265, 14));

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
            $draw->setFontSize(18);
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
            $draw->setFontSize(21);
            $draw->setFontWeight(700);
            $draw->annotation($team2BoxX + 16, $boxY + 46, $this->fitText($image, $draw, $match['team2'], 265, 14));

            // Team 2 Odds Pill
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($team2BoxX + 296, $boxY + 14, $team2BoxX + 384, $boxY + 60, 8, 8);

            $team2Odds = $hasOdds ? sprintf('%.2f', $match['odds']['team2']['price']) : '—';
            $draw->setFillColor($hasOdds ? '#38bdf8' : '#64748b');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(18);
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
     * @param  array{
     *     type?: string,
     *     title: string,
     *     subtitle: string,
     *     total_count?: int,
     *     total_staked?: string,
     *     bets: array<int, array{
     *         type_label: string,
     *         is_parlay: bool,
     *         iid?: ?string,
     *         amount_formatted: string,
     *         multiplier_formatted: string,
     *         payout_formatted: string,
     *         cashout_formatted?: ?string,
     *         cashout_disabled?: bool,
     *         cashout_multiplier?: float,
     *         created_at_formatted?: ?string,
     *         legs: array<int, array{
     *             leg_index: int,
     *             total_legs: int,
     *             status: string,
     *             status_label: string,
     *             game?: ?string,
     *             sport_name?: ?string,
     *             tournament: string,
     *             match_name: string,
     *             match_status: string,
     *             is_live?: bool,
     *             selection: string,
     *             odds: string,
     *             market: string,
     *         }>
     *     }>
     * }  $data
     */
    private function renderBets(array $data): Imagick
    {
        $bets = $data['bets'] ?? [];
        $canvasHeight = $this->betsCanvasHeight($bets);
        $image = new Imagick;
        $image->newImage(self::CANVAS_WIDTH, $canvasHeight, '#090d16', 'png');
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);

        $font = $this->fontPath();
        $draw = new ImagickDraw;
        $draw->setFont($font);
        $draw->setTextAntialias(true);

        // Header Title
        $draw->setFillColor('#f8fafc');
        $draw->setFontSize(48);
        $draw->setFontWeight(800);
        $draw->annotation(46, 68, $this->fitText($image, $draw, $data['title'], 720, 36));

        // Header Subtitle
        $draw->setFillColor('#94a3b8');
        $draw->setFontSize(24);
        $draw->setFontWeight(500);
        $draw->annotation(48, 114, $this->fitText($image, $draw, $data['subtitle'], 720, 18));

        // Top Right Count Badge
        $count = (int) ($data['total_count'] ?? count($bets));
        $countText = $count.' 筆進行中';
        $draw->setFontSize(24);
        $draw->setFontWeight(800);
        $countMetrics = $image->queryFontMetrics($draw, $countText);
        $countBadgeW = (int) round($countMetrics['textWidth']) + 36;
        $countBadgeX2 = 1396;
        $countBadgeX1 = $countBadgeX2 - $countBadgeW;

        $draw->setFillColor('#0284c7');
        $draw->setStrokeColor('#38bdf8');
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($countBadgeX1, 38, $countBadgeX2, 92, 27, 27);

        $draw->setFillColor('#ffffff');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($countBadgeX1 + (int) round($countBadgeW / 2), 73, $countText);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

        // Top Right Total Stake & Balance (if present)
        $hasBalance = ! empty($data['balance_formatted']);
        $hasStaked = ! empty($data['total_staked']);
        $statsRightX = $countBadgeX1 - 24;

        if ($hasStaked) {
            $draw->setFillColor('#38bdf8');
            $draw->setFontSize($hasBalance ? 22 : 26);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $yStaked = $hasBalance ? 56 : 72;
            $draw->annotation($statsRightX, $yStaked, '總投注：'.$data['total_staked']);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        }

        if ($hasBalance) {
            $draw->setFillColor('#34d399');
            $draw->setFontSize(22);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $yBalance = $hasStaked ? 94 : 72;
            $draw->annotation($statsRightX, $yBalance, '資金水位：'.$data['balance_formatted']);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        }

        $cardWidth = 1352;
        $left = 44;
        $y = self::BET_CARDS_TOP;
        $iconsToDraw = [];

        foreach ($bets as $bet) {
            $x = $left;
            $isParlay = (bool) ($bet['is_parlay'] ?? false);
            $legs = $bet['legs'] ?? [];
            $legCount = count($legs);
            $cardHeight = $this->betCardHeight($legCount);

            // Card Container Background & Border
            $draw->setFillColor('#131b2e');
            $draw->setStrokeColor('#22334d');
            $draw->setStrokeWidth(1.5);
            $draw->roundRectangle($x, $y, $x + $cardWidth, $y + $cardHeight, 18, 18);

            // Left Theme Indicator Bar
            $accentColor = $isParlay ? '#a855f7' : '#0ea5e9';
            $draw->setFillColor($accentColor);
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->roundRectangle($x + 2, $y + 16, $x + 7, $y + $cardHeight - 16, 3, 3);

            // --- Card Header: Meta Row (Row 1) ---
            // 1. Bet Type Badge (e.g. 2 關串關 / 單注)
            $typeLabel = (string) ($bet['type_label'] ?? ($isParlay ? "{$legCount} 關串關" : '單注'));
            $draw->setFontSize(20);
            $draw->setFontWeight(800);
            $typeMetrics = $image->queryFontMetrics($draw, $typeLabel);
            $badgeWidth = (int) round($typeMetrics['textWidth']) + 28;

            $draw->setFillColor($isParlay ? '#2e1065' : '#082f49');
            $draw->setStrokeColor($isParlay ? '#9333ea' : '#0284c7');
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle($x + 22, $y + 16, $x + 22 + $badgeWidth, $y + 54, 8, 8);

            $draw->setFillColor($isParlay ? '#f3e8ff' : '#bae6fd');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + 22 + (int) round($badgeWidth / 2), $y + 42, $typeLabel);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // 2. Bet IID
            $currentLeftX = $x + 22 + $badgeWidth + 18;
            $idStr = (string) ($bet['iid'] ?? '');
            if ($idStr !== '') {
                $draw->setFillColor('#94a3b8');
                $draw->setFontSize(24);
                $draw->setFontWeight(700);
                $draw->annotation($currentLeftX, $y + 43, $idStr);
                $idMetrics = $image->queryFontMetrics($draw, $idStr);
                $currentLeftX += (int) round($idMetrics['textWidth']) + 18;
            }

            // 3. Placed Time
            if (! empty($bet['created_at_formatted'])) {
                $draw->setFillColor('#64748b');
                $draw->setFontSize(22);
                $draw->setFontWeight(500);
                $draw->annotation($currentLeftX, $y + 42, $bet['created_at_formatted']);
            }

            // 4. Cashout (即時兌現) Badge (Right side of Meta Row)
            $cashoutFormatted = $bet['cashout_formatted'] ?? null;
            $cashoutDisabled = (bool) ($bet['cashout_disabled'] ?? false);
            $cashoutMultiplier = (float) ($bet['cashout_multiplier'] ?? 0);

            if ($cashoutFormatted !== null || $cashoutDisabled) {
                $isSuspended = $cashoutDisabled;
                $badgeText = $isSuspended ? '即時兌現 暫停 ⏸️' : '即時兌現 '.$cashoutFormatted;

                if ($isSuspended) {
                    $cBg = '#1e293b';
                    $cBorder = '#475569';
                    $cText = '#94a3b8';
                } elseif ($cashoutMultiplier >= 1.0) {
                    $cBg = '#064e3b';
                    $cBorder = '#059669';
                    $cText = '#34d399';
                } else {
                    $cBg = '#451a03';
                    $cBorder = '#d97706';
                    $cText = '#fbbf24';
                }

                $draw->setFontSize(20);
                $draw->setFontWeight(800);
                $cMetrics = $image->queryFontMetrics($draw, $badgeText);
                $cBadgeW = (int) round($cMetrics['textWidth']) + 28;
                $cBadgeX2 = $x + $cardWidth - 22;
                $cBadgeX1 = $cBadgeX2 - $cBadgeW;

                $draw->setFillColor($cBg);
                $draw->setStrokeColor($cBorder);
                $draw->setStrokeWidth(1);
                $draw->roundRectangle($cBadgeX1, $y + 16, $cBadgeX2, $y + 54, 8, 8);

                $draw->setFillColor($cText);
                $draw->setStrokeColor('none');
                $draw->setStrokeWidth(0);
                $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                $draw->annotation($cBadgeX1 + (int) round($cBadgeW / 2), $y + 42, $badgeText);
                $draw->setTextAlignment(Imagick::ALIGN_LEFT);
            }

            // --- Card Header: Financial Stats Bar (Row 2) ---
            $statsX1 = $x + 22;
            $statsX2 = $x + $cardWidth - 22;
            $statsWidth = $statsX2 - $statsX1;
            $colW = $statsWidth / 3;

            $draw->setFillColor('#0b1322');
            $draw->setStrokeColor('#1e2d45');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($statsX1, $y + 68, $statsX2, $y + 144, 10, 10);

            // Column Dividers
            $draw->setStrokeColor('#1e2d45');
            $draw->setStrokeWidth(1);
            $draw->line($statsX1 + (int) round($colW), $y + 78, $statsX1 + (int) round($colW), $y + 134);
            $draw->line($statsX1 + (int) round($colW * 2), $y + 78, $statsX1 + (int) round($colW * 2), $y + 134);

            // Col 1: 投注金額
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(18);
            $draw->setFontWeight(500);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($statsX1 + (int) round($colW * 0.5), $y + 95, '投注金額');

            $draw->setFillColor('#f8fafc');
            $draw->setFontSize(28);
            $draw->setFontWeight(800);
            $draw->annotation($statsX1 + (int) round($colW * 0.5), $y + 131, $bet['amount_formatted']);

            // Col 2: 總賠率
            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(18);
            $draw->setFontWeight(500);
            $draw->annotation($statsX1 + (int) round($colW * 1.5), $y + 95, '總賠率');

            $draw->setFillColor('#38bdf8');
            $draw->setFontSize(28);
            $draw->setFontWeight(800);
            $draw->annotation($statsX1 + (int) round($colW * 1.5), $y + 131, (string) $bet['multiplier_formatted'].'x');

            // Col 3: 預估返還
            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(18);
            $draw->setFontWeight(500);
            $draw->annotation($statsX1 + (int) round($colW * 2.5), $y + 95, '預估返還');

            $draw->setFillColor('#4ade80');
            $draw->setFontSize(30);
            $draw->setFontWeight(800);
            $draw->annotation($statsX1 + (int) round($colW * 2.5), $y + 131, $bet['payout_formatted']);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // --- Legs list (Row 3 onwards) ---
            $legY = $y + self::BET_CARD_HEADER_HEIGHT;

            foreach ($legs as $legIndex => $leg) {
                $rawStatus = mb_strtolower((string) ($leg['status'] ?? 'pending'));
                $statusTheme = match ($rawStatus) {
                    'won' => ['bg' => '#052e16', 'border' => '#16a34a', 'text' => '#4ade80', 'label' => '已過 ✅'],
                    'lost' => ['bg' => '#3b0811', 'border' => '#dc2626', 'text' => '#fca5a5', 'label' => '未過 ❌'],
                    'void', 'refund', 'cancelled' => ['bg' => '#1e293b', 'border' => '#475569', 'text' => '#cbd5e1', 'label' => '退本金 ⚪'],
                    default => ['bg' => '#082f49', 'border' => '#0284c7', 'text' => '#38bdf8', 'label' => '進行中 ⏳'],
                };

                // Leg Box Background
                $draw->setFillColor('#162238');
                $draw->setStrokeColor('#233554');
                $draw->setStrokeWidth(1.2);
                $draw->roundRectangle($x + 22, $legY, $x + $cardWidth - 22, $legY + self::LEG_BOX_HEIGHT, 10, 10);

                // --- Leg Sub-row 1: Badges, Tournament & Live Status ---
                if ($isParlay) {
                    // Leg index badge: 1/2
                    $idxText = sprintf('%d/%d', $leg['leg_index'] ?? ($legIndex + 1), $leg['total_legs'] ?? $legCount);
                    $draw->setFillColor('#1e293b');
                    $draw->setStrokeColor('#334155');
                    $draw->setStrokeWidth(1);
                    $draw->roundRectangle($x + 36, $legY + 12, $x + 96, $legY + 42, 6, 6);

                    $draw->setFillColor('#cbd5e1');
                    $draw->setStrokeColor('none');
                    $draw->setStrokeWidth(0);
                    $draw->setFontSize(18);
                    $draw->setFontWeight(700);
                    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                    $draw->annotation($x + 66, $legY + 33, $idxText);

                    // Status badge
                    $draw->setFillColor($statusTheme['bg']);
                    $draw->setStrokeColor($statusTheme['border']);
                    $draw->setStrokeWidth(1);
                    $draw->roundRectangle($x + 104, $legY + 12, $x + 216, $legY + 42, 6, 6);

                    $draw->setFillColor($statusTheme['text']);
                    $draw->setStrokeColor('none');
                    $draw->setStrokeWidth(0);
                    $draw->setFontSize(18);
                    $draw->setFontWeight(800);
                    $draw->annotation($x + 160, $legY + 33, $statusTheme['label']);
                    $draw->setTextAlignment(Imagick::ALIGN_LEFT);

                    $tourStartX = $x + 228;
                } else {
                    // Single bet: Status badge
                    $draw->setFillColor($statusTheme['bg']);
                    $draw->setStrokeColor($statusTheme['border']);
                    $draw->setStrokeWidth(1);
                    $draw->roundRectangle($x + 36, $legY + 12, $x + 156, $legY + 42, 6, 6);

                    $draw->setFillColor($statusTheme['text']);
                    $draw->setStrokeColor('none');
                    $draw->setStrokeWidth(0);
                    $draw->setFontSize(18);
                    $draw->setFontWeight(800);
                    $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                    $draw->annotation($x + 96, $legY + 33, $statusTheme['label']);
                    $draw->setTextAlignment(Imagick::ALIGN_LEFT);

                    $tourStartX = $x + 168;
                }

                // Match Status / Live Score (Right-aligned in Leg Sub-row 1)
                $rightMargin = $x + $cardWidth - 36;
                $matchStatus = (string) ($leg['match_status'] ?? '');
                $isLive = (bool) ($leg['is_live'] ?? false);

                if ($matchStatus !== '') {
                    if ($isLive) {
                        $draw->setFontSize(18);
                        $draw->setFontWeight(700);
                        $liveMetrics = $image->queryFontMetrics($draw, $matchStatus);
                        $liveW = (int) round($liveMetrics['textWidth']);
                        $badgeW = max(130, min(400, $liveW + 36));
                        $badgeX2 = $rightMargin;
                        $badgeX1 = $badgeX2 - $badgeW;

                        $draw->setFillColor('#3b0811');
                        $draw->setStrokeColor('#ef4444');
                        $draw->setStrokeWidth(1);
                        $draw->roundRectangle($badgeX1, $legY + 12, $badgeX2, $legY + 42, 6, 6);

                        // Red dot
                        $draw->setFillColor('#ef4444');
                        $draw->setStrokeColor('none');
                        $draw->setStrokeWidth(0);
                        $draw->circle($badgeX1 + 14, $legY + 27, $badgeX1 + 18, $legY + 27);

                        $draw->setFillColor('#fca5a5');
                        $draw->setFontSize(18);
                        $draw->setFontWeight(700);
                        $fitStatus = $this->fitText($image, $draw, $matchStatus, $badgeW - 28, 13);
                        $draw->annotation($badgeX1 + 25, $legY + 33, $fitStatus);

                        $availTourWidth = $badgeX1 - 16 - $tourStartX;
                    } else {
                        $draw->setFillColor('#64748b');
                        $draw->setStrokeColor('none');
                        $draw->setStrokeWidth(0);
                        $draw->setFontSize(20);
                        $draw->setFontWeight(600);
                        $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
                        $draw->annotation($rightMargin, $legY + 33, $matchStatus);
                        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

                        $timeMetrics = $image->queryFontMetrics($draw, $matchStatus);
                        $availTourWidth = $rightMargin - (int) round($timeMetrics['textWidth']) - 20 - $tourStartX;
                    }
                } else {
                    $availTourWidth = $rightMargin - $tourStartX;
                }

                // Sport icon & Tournament Name (Middle of Leg Sub-row 1)
                $game = $leg['game'] ?? null;
                $iconPath = $this->iconPathForGame($game);

                if ($iconPath !== null) {
                    $iconsToDraw[] = [
                        'path' => $iconPath,
                        'x' => $tourStartX,
                        'y' => $legY + 14,
                        'size' => 24,
                    ];
                    $tourStartX += 30;
                    $availTourWidth -= 30;
                }

                $sportName = (string) ($leg['sport_name'] ?? '');
                $sportPrefix = $sportName !== '' ? "【{$sportName}】" : '';
                $tourText = $sportPrefix.(string) ($leg['tournament'] ?? '');
                $draw->setFillColor('#94a3b8');
                $draw->setStrokeColor('none');
                $draw->setStrokeWidth(0);
                $draw->setFontSize(21);
                $draw->setFontWeight(600);
                $fittedTour = $this->fitText($image, $draw, $tourText, max(100, $availTourWidth), 14);
                $draw->annotation($tourStartX, $legY + 33, $fittedTour);

                // --- Leg Sub-row 2: Match Teams ---
                $draw->setFillColor('#f8fafc');
                $draw->setStrokeColor('none');
                $draw->setStrokeWidth(0);
                $draw->setFontSize(32);
                $draw->setFontWeight(800);
                $matchName = (string) ($leg['match_name'] ?? '');
                $fittedMatch = $this->fitText($image, $draw, $matchName, 1230, 22);
                $draw->annotation($x + 36, $legY + 80, $fittedMatch);

                // --- Leg Sub-row 3: Bet Selection & Market Strip ---
                $draw->setFillColor('#0e1a2d');
                $draw->setStrokeColor('#1d3354');
                $draw->setStrokeWidth(1);
                $draw->roundRectangle($x + 34, $legY + 96, $x + $cardWidth - 34, $legY + 136, 8, 8);

                $marketText = (string) ($leg['market'] ?? '');
                if ($marketText !== '') {
                    $draw->setFillColor('#94a3b8');
                    $draw->setStrokeColor('none');
                    $draw->setStrokeWidth(0);
                    $draw->setFontSize(20);
                    $draw->setFontWeight(500);
                    $fittedMarket = $this->fitText($image, $draw, '盤口：'.$marketText, 560, 14);
                    $draw->annotation($x + 48, $legY + 123, $fittedMarket);
                }

                $selOdds = (string) ($leg['selection'] ?? '').' @ '.(string) ($leg['odds'] ?? '');
                $selColor = match ($rawStatus) {
                    'won' => '#4ade80',
                    'lost' => '#f87171',
                    default => '#38bdf8',
                };
                $draw->setFillColor($selColor);
                $draw->setStrokeColor('none');
                $draw->setStrokeWidth(0);
                $draw->setFontSize(24);
                $draw->setFontWeight(800);
                $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
                $fittedSel = $this->fitText($image, $draw, $selOdds, 620, 16);
                $draw->annotation($x + $cardWidth - 48, $legY + 123, $fittedSel);
                $draw->setTextAlignment(Imagick::ALIGN_LEFT);

                $legY += self::LEG_BOX_HEIGHT + self::LEG_GAP;
            }

            $y += $cardHeight + self::BET_CARD_GAP;
        }

        $image->drawImage($draw);

        foreach ($iconsToDraw as $iconData) {
            try {
                $icon = new Imagick($iconData['path']);
                $icon->resizeImage($iconData['size'], $iconData['size'], Imagick::FILTER_LANCZOS, 1);
                $image->compositeImage($icon, Imagick::COMPOSITE_OVER, $iconData['x'], $iconData['y']);
                $icon->clear();
            } catch (Throwable) {
                // Ignore icon loading error
            }
        }

        $image->stripImage();

        return $image;
    }

    private function betCardHeight(int $legCount): int
    {
        return self::BET_CARD_HEADER_HEIGHT + ($legCount * (self::LEG_BOX_HEIGHT + self::LEG_GAP)) + self::BET_CARD_BOTTOM_PAD;
    }

    /**
     * @param  array<int, array{legs?: array<int, mixed>}>  $bets
     */
    private function betsCanvasHeight(array $bets): int
    {
        $height = self::BET_CARDS_TOP;

        foreach ($bets as $bet) {
            $legCount = count($bet['legs'] ?? []);
            $height += $this->betCardHeight($legCount) + self::BET_CARD_GAP;
        }

        return $height + self::CANVAS_BOTTOM_PADDING;
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
            $draw->setFontSize(11);
            $draw->setFontWeight(500);
            $draw->annotation($x, $rowY + 15, (string) ($result['date'] ?? '—'));

            // 2. Format Badge (e.g. BO1 / BO2 / BO3 / BO5)
            $formatTheme = $this->formatTheme($result['format'] ?? null);
            $draw->setFillColor($formatTheme['bg']);
            $draw->setStrokeColor($formatTheme['border']);
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x + 70, $rowY + 1, $x + 104, $rowY + 19, 3, 3);
            $draw->setFillColor($formatTheme['text']);
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(10);
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
            '/System/Library/Fonts/PingFang.ttc',
            '/System/Library/Fonts/STHeiti Medium.ttc',
            '/System/Library/Fonts/STHeiti Light.ttc',
            '/System/Library/Fonts/Hiragino Sans GB.ttc',
            '/Library/Fonts/Arial Unicode.ttf',
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

        // Live Match Hub Container (88px wide, 62px tall, centered with 6px vertical breathing margins)
        $hubLeft = $centerX - 44;
        $hubRight = $centerX + 44;
        $hubTop = $boxY + 6;
        $hubBottom = $boxY + 68;

        $draw->setFillColor('#190d16');
        $draw->setStrokeColor('#e11d48');
        $draw->setStrokeWidth(1.2);
        $draw->roundRectangle($hubLeft, $hubTop, $hubRight, $hubBottom, 12, 12);

        $parsedSeries = $this->parseScorePair($seriesScore);
        $parsedMap = $this->parseScorePair($mapScore);

        // 1. Top: Enhanced 大分 (Series Score / Map Win Indicator Slots)
        if ($parsedSeries !== null) {
            [$team1Wins, $team2Wins] = $parsedSeries;
            $reqWins = $this->seriesWinSlots($match['format'] ?? null);

            $this->drawMapPips($draw, $centerX - 22, $boxY + 12, $team1Wins, $reqWins, '#38bdf8', '#7dd3fc');
            $this->drawMapPips($draw, $centerX + 22, $boxY + 12, $team2Wins, $reqWins, '#fb7185', '#fda4af');
        }

        // 2. Middle: Scoreboard Digits (Displays 小分 / Current Game Score)
        $s1 = null;
        $s2 = null;

        if ($parsedMap !== null) {
            [$s1, $s2] = $parsedMap;
        } elseif ($mapScore !== null) {
            $draw->setFillColor('#ffffff');
            $draw->setStrokeColor('none');
            $draw->setFontSize(15);
            $draw->setFontWeight(800);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($centerX, $boxY + 38, $mapScore);
        } else {
            // Live map score starting at 0:0 when not yet registered
            [$s1, $s2] = [0, 0];
        }

        if ($s1 !== null && $s2 !== null) {
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontWeight(800);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);

            $isDoubleDigit = $s1 >= 10 || $s2 >= 10;
            $fontSize = $isDoubleDigit ? 19 : 22;
            $scoreOffset = $isDoubleDigit ? 20 : 18;

            $draw->setFontSize($fontSize);

            // Team 1 score digit (Left)
            $team1Color = $s1 > $s2 ? '#ffffff' : ($s1 < $s2 ? '#94a3b8' : '#f1f5f9');
            $draw->setFillColor($team1Color);
            $draw->annotation($centerX - $scoreOffset, $boxY + 38, (string) $s1);

            // Colon separator
            $draw->setFillColor('#f43f5e');
            $draw->setFontSize(16);
            $draw->annotation($centerX, $boxY + 37, ':');

            // Team 2 score digit (Right)
            $draw->setFontSize($fontSize);
            $team2Color = $s2 > $s1 ? '#ffffff' : ($s2 < $s1 ? '#94a3b8' : '#f1f5f9');
            $draw->setFillColor($team2Color);
            $draw->annotation($centerX + $scoreOffset, $boxY + 38, (string) $s2);
        }

        // 3. Bottom: Live Status Pill & Current Game Indicator
        $pillLeft = $centerX - 35;
        $pillRight = $centerX + 35;
        $pillTop = $boxY + 47;
        $pillBottom = $boxY + 63;

        $draw->setFillColor('#2d0d17');
        $draw->setStrokeColor('#9f1239');
        $draw->setStrokeWidth(0.8);
        $draw->roundRectangle($pillLeft, $pillTop, $pillRight, $pillBottom, 8, 8);

        // Live red dot
        $draw->setFillColor('#ef4444');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);

        if ($parsedSeries !== null) {
            [$team1Wins, $team2Wins] = $parsedSeries;
            $maxGames = preg_match('/BO(\d+)/i', (string) ($match['format'] ?? ''), $matches) ? (int) $matches[1] : 99;
            $currentGame = min(($team1Wins + $team2Wins) + 1, $maxGames);
            $gameLabel = '第 '.$currentGame.' 局';

            $dotX = $centerX - 24;
            $draw->circle($dotX, $boxY + 55, $dotX + 2.2, $boxY + 55);

            $draw->setFillColor('#fecdd3');
            $draw->setFontSize(10);
            $draw->setFontWeight(700);
            $draw->annotation($centerX + 6, $boxY + 58.5, $gameLabel);
        } else {
            $dotX = $centerX - 17;
            $draw->circle($dotX, $boxY + 55, $dotX + 2.2, $boxY + 55);

            $draw->setFillColor('#fecdd3');
            $draw->setFontSize(10);
            $draw->setFontWeight(700);
            $draw->annotation($centerX + 5, $boxY + 58.5, 'LIVE');
        }

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
        string $wonBorderColor,
    ): void {
        $pipWidth = match (true) {
            $totalRequired <= 1 => 20,
            $totalRequired === 2 => 14,
            $totalRequired === 3 => 10,
            default => 8,
        };
        $pipHeight = 6;
        $gap = $totalRequired >= 3 ? 3 : 4;
        $totalWidth = ($totalRequired * $pipWidth) + (($totalRequired - 1) * $gap);
        $startX = $centerX - (int) round($totalWidth / 2);

        for ($i = 0; $i < $totalRequired; $i++) {
            $left = $startX + ($i * ($pipWidth + $gap));
            $isWon = $i < min($wins, $totalRequired);

            if ($isWon) {
                $draw->setFillColor($wonColor);
                $draw->setStrokeColor($wonBorderColor);
                $draw->setStrokeWidth(1);
            } else {
                $draw->setFillColor('#23121d');
                $draw->setStrokeColor('#5b2339');
                $draw->setStrokeWidth(1);
            }

            $draw->roundRectangle($left, $y, $left + $pipWidth, $y + $pipHeight, 2, 2);
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderBetHistory(array $data): Imagick
    {
        $bets = $data['bets'] ?? [];
        $summary = $data['summary'] ?? [];
        $omittedCount = (int) ($data['omitted_count'] ?? 0);
        $isEmpty = empty($bets);
        $canvasHeight = $this->betHistoryCanvasHeight($bets, $isEmpty, $omittedCount);

        $image = new Imagick;
        $image->newImage(self::CANVAS_WIDTH, $canvasHeight, '#090d16', 'png');
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);

        $font = $this->fontPath();
        $draw = new ImagickDraw;
        $draw->setFont($font);
        $draw->setTextAntialias(true);

        // 1. Header Title
        $draw->setFillColor('#f8fafc');
        $draw->setFontSize(48);
        $draw->setFontWeight(800);
        $draw->annotation(46, 68, $this->fitText($image, $draw, $data['title'] ?? 'Stake 體育投注｜每日損益與紀錄', 760, 36));

        // 2. Header Subtitle
        $draw->setFillColor('#94a3b8');
        $draw->setFontSize(22);
        $draw->setFontWeight(500);
        $draw->annotation(48, 114, $this->fitText($image, $draw, $data['subtitle'] ?? '', 760, 18));

        // 3. Header Top Right Badges (Count Badge & Balance)
        $totalCount = (int) ($summary['total_count'] ?? count($bets));
        $countText = $totalCount.' 筆注單';
        $draw->setFontSize(24);
        $draw->setFontWeight(800);
        $countMetrics = $image->queryFontMetrics($draw, $countText);
        $countBadgeW = (int) round($countMetrics['textWidth']) + 36;
        $countBadgeX2 = 1396;
        $countBadgeX1 = $countBadgeX2 - $countBadgeW;

        $draw->setFillColor('#0284c7');
        $draw->setStrokeColor('#38bdf8');
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($countBadgeX1, 38, $countBadgeX2, 92, 27, 27);

        $draw->setFillColor('#ffffff');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($countBadgeX1 + (int) round($countBadgeW / 2), 73, $countText);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

        if (! empty($data['balance_formatted'])) {
            $draw->setFillColor('#34d399');
            $draw->setFontSize(22);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $draw->annotation($countBadgeX1 - 24, 72, '資金水位：'.$data['balance_formatted']);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        }

        // 4. KPI Dashboard Cards (y = 146 ~ 302, height = 156)
        $dashY = 146;
        $dashHeight = 156;

        // Card 1: 淨損益 (Net Profit/Loss)
        $c1X1 = 44;
        $c1W = 344;
        $c1X2 = $c1X1 + $c1W;
        $netProfitVal = (float) ($summary['net_profit_val'] ?? 0);
        $isProfitable = $netProfitVal > 0.001;
        $isLoss = $netProfitVal < -0.001;

        $c1Bg = $isProfitable ? '#064e3b' : ($isLoss ? '#450a0a' : '#1e293b');
        $c1Border = $isProfitable ? '#059669' : ($isLoss ? '#dc2626' : '#475569');
        $c1Text = $isProfitable ? '#34d399' : ($isLoss ? '#f87171' : '#cbd5e1');

        $draw->setFillColor($c1Bg);
        $draw->setStrokeColor($c1Border);
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($c1X1, $dashY, $c1X2, $dashY + $dashHeight, 14, 14);

        $draw->setFillColor($isProfitable ? '#a7f3d0' : ($isLoss ? '#fecaca' : '#94a3b8'));
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFontSize(18);
        $draw->setFontWeight(600);
        $draw->annotation($c1X1 + 22, $dashY + 36, '淨損益 (Net P/L)');

        $draw->setFillColor($c1Text);
        $draw->setFontSize(34);
        $draw->setFontWeight(800);
        $netProfitText = (string) ($summary['net_profit'] ?? '0.00 USDT');
        $draw->annotation($c1X1 + 22, $dashY + 84, $netProfitText);

        $tagLabel = $isProfitable ? '▲ 盈利' : ($isLoss ? '▼ 虧損' : '平手');
        $draw->setFontSize(18);
        $draw->setFontWeight(700);
        $draw->annotation($c1X1 + 22, $dashY + 124, $tagLabel);

        // Card 2: 總投注 & 總返還 (x: 406, w: 304)
        $c2X1 = 406;
        $c2W = 304;
        $c2X2 = $c2X1 + $c2W;

        $draw->setFillColor('#0f172a');
        $draw->setStrokeColor('#1e2d45');
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($c2X1, $dashY, $c2X2, $dashY + $dashHeight, 14, 14);

        $draw->setFillColor('#94a3b8');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFontSize(18);
        $draw->setFontWeight(600);
        $draw->annotation($c2X1 + 20, $dashY + 36, '總投注 / 總返還');

        $draw->setFillColor('#cbd5e1');
        $draw->setFontSize(22);
        $draw->setFontWeight(700);
        $draw->annotation($c2X1 + 20, $dashY + 76, '投注：'.($summary['total_staked'] ?? '0 USDT'));

        $draw->setFillColor('#38bdf8');
        $draw->setFontSize(22);
        $draw->setFontWeight(700);
        $draw->annotation($c2X1 + 20, $dashY + 118, '返還：'.($summary['total_payout'] ?? '0 USDT'));

        // Card 3: 勝率 & 投資報酬率 (x: 728, w: 304)
        $c3X1 = 728;
        $c3W = 304;
        $c3X2 = $c3X1 + $c3W;

        $draw->setFillColor('#0f172a');
        $draw->setStrokeColor('#1e2d45');
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($c3X1, $dashY, $c3X2, $dashY + $dashHeight, 14, 14);

        $draw->setFillColor('#94a3b8');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFontSize(18);
        $draw->setFontWeight(600);
        $draw->annotation($c3X1 + 20, $dashY + 36, '勝率 / 投資報酬率');

        $draw->setFillColor('#fbbf24');
        $draw->setFontSize(22);
        $draw->setFontWeight(700);
        $draw->annotation($c3X1 + 20, $dashY + 76, '勝率：'.($summary['win_rate'] ?? '0.0%'));

        $roiVal = (float) ($summary['roi_val'] ?? 0);
        $roiColor = $roiVal > 0.001 ? '#34d399' : ($roiVal < -0.001 ? '#f87171' : '#cbd5e1');
        $draw->setFillColor($roiColor);
        $draw->setFontSize(22);
        $draw->setFontWeight(700);
        $draw->annotation($c3X1 + 20, $dashY + 118, 'ROI：'.($summary['roi'] ?? '0.0%'));

        // Card 4: 戰績總覽 (x: 1050, w: 346)
        $c4X1 = 1050;
        $c4W = 346;
        $c4X2 = $c4X1 + $c4W;

        $draw->setFillColor('#0f172a');
        $draw->setStrokeColor('#1e2d45');
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($c4X1, $dashY, $c4X2, $dashY + $dashHeight, 14, 14);

        $draw->setFillColor('#94a3b8');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFontSize(18);
        $draw->setFontWeight(600);
        $draw->annotation($c4X1 + 20, $dashY + 36, '戰績總覽 (Record)');

        $draw->setFillColor('#f8fafc');
        $draw->setFontSize(22);
        $draw->setFontWeight(700);
        $draw->annotation($c4X1 + 20, $dashY + 76, ($summary['won_count'] ?? 0).' 勝  '.($summary['lost_count'] ?? 0).' 負');

        $extraDetailParts = [];
        if (($summary['cashout_count'] ?? 0) > 0) {
            $extraDetailParts[] = $summary['cashout_count'].' 兌現';
        }
        if (($summary['active_count'] ?? 0) > 0) {
            $extraDetailParts[] = $summary['active_count'].' 進行中';
        }
        if (($summary['void_count'] ?? 0) > 0) {
            $extraDetailParts[] = $summary['void_count'].' 退款';
        }
        $extraDetail = $extraDetailParts !== [] ? implode('  ', $extraDetailParts) : '全部結算';

        $draw->setFillColor('#94a3b8');
        $draw->setFontSize(20);
        $draw->setFontWeight(500);
        $draw->annotation($c4X1 + 20, $dashY + 118, $extraDetail);

        // 5. Bet List or Empty State
        $cardWidth = 1352;
        $left = 44;
        $y = $dashY + $dashHeight + 24;

        if ($isEmpty) {
            $emptyHeight = 220;
            $draw->setFillColor('#0f172a');
            $draw->setStrokeColor('#1e2d45');
            $draw->setStrokeWidth(1.5);
            $draw->roundRectangle($left, $y, $left + $cardWidth, $y + $emptyHeight, 16, 16);

            $draw->setFillColor('#94a3b8');
            $draw->setStrokeColor('none');
            $draw->setFontSize(32);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($left + (int) round($cardWidth / 2), $y + 100, '該日期查無投注紀錄');

            $draw->setFillColor('#64748b');
            $draw->setFontSize(22);
            $draw->setFontWeight(500);
            $draw->annotation($left + (int) round($cardWidth / 2), $y + 148, '輸入 !bet 可查詢當前進行中的即時注單');
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            $image->drawImage($draw);
            $draw->clear();

            return $image;
        }

        foreach ($bets as $bet) {
            $legs = $bet['legs'] ?? [];
            $legCount = max(1, count($legs));
            $cardHeight = $this->betHistoryCardHeight($legCount);
            $status = $bet['status'] ?? 'pending';

            $statusTheme = match ($status) {
                'won' => ['bar' => '#10b981', 'bg' => '#064e3b', 'border' => '#059669', 'text' => '#34d399', 'label' => '獲勝'],
                'lost' => ['bar' => '#ef4444', 'bg' => '#450a0a', 'border' => '#dc2626', 'text' => '#f87171', 'label' => '未中獎'],
                'cashout' => ['bar' => '#f59e0b', 'bg' => '#451a03', 'border' => '#d97706', 'text' => '#fbbf24', 'label' => '已兌現'],
                'void' => ['bar' => '#64748b', 'bg' => '#1e293b', 'border' => '#475569', 'text' => '#cbd5e1', 'label' => '退款'],
                default => ['bar' => '#0ea5e9', 'bg' => '#082f49', 'border' => '#0284c7', 'text' => '#38bdf8', 'label' => '進行中'],
            };

            // Card Container
            $draw->setFillColor('#131b2e');
            $draw->setStrokeColor('#22334d');
            $draw->setStrokeWidth(1.5);
            $draw->roundRectangle($left, $y, $left + $cardWidth, $y + $cardHeight, 16, 16);

            // Left Indicator Bar
            $draw->setFillColor($statusTheme['bar']);
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->roundRectangle($left + 2, $y + 14, $left + 7, $y + $cardHeight - 14, 3, 3);

            // 1. Status Badge
            $sBadgeW = 86;
            $draw->setFillColor($statusTheme['bg']);
            $draw->setStrokeColor($statusTheme['border']);
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle($left + 20, $y + 14, $left + 20 + $sBadgeW, $y + 48, 6, 6);

            $draw->setFillColor($statusTheme['text']);
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(18);
            $draw->setFontWeight(800);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($left + 20 + (int) round($sBadgeW / 2), $y + 38, $statusTheme['label']);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // 2. Type Badge
            $typeLabel = (bool) ($bet['is_parlay'] ?? false) ? "{$legCount} 關串關" : '單注';
            $draw->setFontSize(18);
            $draw->setFontWeight(700);
            $tMetrics = $image->queryFontMetrics($draw, $typeLabel);
            $tBadgeW = (int) round($tMetrics['textWidth']) + 22;
            $tBadgeX = $left + 20 + $sBadgeW + 12;

            $draw->setFillColor('#1e293b');
            $draw->setStrokeColor('#334155');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($tBadgeX, $y + 14, $tBadgeX + $tBadgeW, $y + 48, 6, 6);

            $draw->setFillColor('#cbd5e1');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($tBadgeX + (int) round($tBadgeW / 2), $y + 38, $typeLabel);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // 3. IID & Time
            $infoX = $tBadgeX + $tBadgeW + 16;
            $draw->setFillColor('#64748b');
            $draw->setFontSize(20);
            $draw->setFontWeight(600);
            $iidStr = ! empty($bet['iid']) ? '#'.$bet['iid'].'  ' : '';
            $timeStr = $bet['created_time_only'] ?? ($bet['created_at_formatted'] ?? '');
            $draw->annotation($infoX, $y + 38, $iidStr.$timeStr);

            // 4. Profit on Top Right
            $profitStr = (string) ($bet['profit_formatted'] ?? '');
            $profitVal = (float) ($bet['profit_val'] ?? 0);
            $pColor = $status === 'pending'
                ? '#38bdf8'
                : ($profitVal > 0.001 ? '#34d399' : ($profitVal < -0.001 ? '#f87171' : '#94a3b8'));

            $draw->setFillColor($pColor);
            $draw->setFontSize(26);
            $draw->setFontWeight(800);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $draw->annotation($left + $cardWidth - 24, $y + 40, $profitStr);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Middle: Legs Detail
            $legStartY = $y + 76;
            foreach ($legs as $idx => $leg) {
                $curLegY = $legStartY + ($idx * 46);

                $sportStr = ! empty($leg['sport_name']) ? "【{$leg['sport_name']}】" : '';
                $tournStr = ! empty($leg['tournament_name']) ? $leg['tournament_name'].' ｜ ' : '';
                $matchStr = $leg['fixture_name'] ?? '';
                $legHeader = $this->fitText($image, $draw, $sportStr.$tournStr.$matchStr, 640, 20);

                $draw->setFillColor('#94a3b8');
                $draw->setFontSize(18);
                $draw->setFontWeight(500);
                $draw->annotation($left + 24, $curLegY, $legHeader);

                $outcomeText = sprintf(
                    '%s @ %.2f  %s',
                    $leg['outcome_name'] ?? '',
                    (float) ($leg['odds'] ?? 1.0),
                    $leg['status_symbol'] ?? ''
                );
                $draw->setFillColor('#f8fafc');
                $draw->setFontSize(20);
                $draw->setFontWeight(700);
                $draw->annotation($left + 680, $curLegY, $outcomeText);
            }

            // Bottom Right Financial Bar
            $finY = $y + $cardHeight - 16;
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $draw->setFontSize(18);

            $finText = sprintf(
                '投注：%s   賠率：%s   返還：%s',
                $bet['amount_formatted'] ?? '',
                $bet['odds_formatted'] ?? '',
                $bet['payout_formatted'] ?? ''
            );
            $draw->setFillColor('#94a3b8');
            $draw->setFontWeight(500);
            $draw->annotation($left + $cardWidth - 24, $finY, $finText);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            $y += $cardHeight + 16;
        }

        if ($omittedCount > 0) {
            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(20);
            $draw->setFontWeight(500);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation((int) round(self::CANVAS_WIDTH / 2), $y + 36, sprintf('另有 %d 筆注單未列出，請至 Stake 官網查看完整明細', $omittedCount));
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);
            $y += 56;
        }

        $image->drawImage($draw);
        $draw->clear();

        return $image;
    }

    private function betHistoryCardHeight(int $legCount): int
    {
        return 112 + ($legCount * 46);
    }

    private function betHistoryCanvasHeight(array $bets, bool $isEmpty, int $omittedCount = 0): int
    {
        $height = 146 + 156 + 24;

        if ($isEmpty) {
            return $height + 220 + self::CANVAS_BOTTOM_PADDING;
        }

        foreach ($bets as $bet) {
            $legCount = max(1, count($bet['legs'] ?? []));
            $height += $this->betHistoryCardHeight($legCount) + 16;
        }

        if ($omittedCount > 0) {
            $height += 56;
        }

        return $height + self::CANVAS_BOTTOM_PADDING;
    }

    private function balanceChartCanvasHeight(bool $isEmpty): int
    {
        return $isEmpty ? 620 : 1080;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderBalanceChart(array $data): Imagick
    {
        $bars = $data['bars'] ?? [];
        $summary = $data['summary'] ?? [];
        $isEmpty = empty($bars);
        $canvasHeight = $this->balanceChartCanvasHeight($isEmpty);

        $image = new Imagick;
        $image->newImage(self::CANVAS_WIDTH, $canvasHeight, '#090d16', 'png');
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);

        $font = $this->fontPath();
        $draw = new ImagickDraw;
        $draw->setFont($font);
        $draw->setTextAntialias(true);

        // 1. Header Title
        $draw->setFillColor('#f8fafc');
        $draw->setFontSize(48);
        $draw->setFontWeight(800);
        $draw->annotation(46, 68, $this->fitText($image, $draw, $data['title'] ?? 'Stake 體育投注｜資金水位長條圖', 760, 36));

        // 2. Header Subtitle
        $draw->setFillColor('#94a3b8');
        $draw->setFontSize(22);
        $draw->setFontWeight(500);
        $draw->annotation(48, 114, $this->fitText($image, $draw, $data['subtitle'] ?? '', 760, 18));

        // 3. Header Top Right Badges (Count Badge & Balance)
        $totalCount = (int) ($summary['total_bets'] ?? count($bars));
        $countText = $totalCount.' 筆結盤';
        $draw->setFontSize(24);
        $draw->setFontWeight(800);
        $countMetrics = $image->queryFontMetrics($draw, $countText);
        $countBadgeW = (int) round($countMetrics['textWidth']) + 36;
        $countBadgeX2 = 1396;
        $countBadgeX1 = $countBadgeX2 - $countBadgeW;

        $draw->setFillColor('#0284c7');
        $draw->setStrokeColor('#38bdf8');
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($countBadgeX1, 38, $countBadgeX2, 92, 27, 27);

        $draw->setFillColor('#ffffff');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($countBadgeX1 + (int) round($countBadgeW / 2), 73, $countText);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

        if (! empty($data['current_balance_formatted'])) {
            $draw->setFillColor('#34d399');
            $draw->setFontSize(22);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $draw->annotation($countBadgeX1 - 24, 72, '目前水位：'.$data['current_balance_formatted']);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        }

        // 4. KPI Dashboard Cards (y = 146 ~ 296, height = 150)
        $dashY = 146;
        $dashHeight = 150;

        // Card 1: 目前水位 (Current Balance)
        $c1X1 = 44;
        $c1W = 344;
        $c1X2 = $c1X1 + $c1W;
        $draw->setFillColor('#0f172a');
        $draw->setStrokeColor('#1e2d45');
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($c1X1, $dashY, $c1X2, $dashY + $dashHeight, 14, 14);

        $draw->setFillColor('#94a3b8');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFontSize(18);
        $draw->setFontWeight(600);
        $draw->annotation($c1X1 + 22, $dashY + 36, '目前資金水位 (Current)');

        $draw->setFillColor('#38bdf8');
        $draw->setFontSize(30);
        $draw->setFontWeight(800);
        $draw->annotation($c1X1 + 22, $dashY + 82, (string) ($summary['current_balance'] ?? '0 USDT'));

        $draw->setFillColor('#64748b');
        $draw->setFontSize(18);
        $draw->setFontWeight(600);
        $draw->annotation($c1X1 + 22, $dashY + 120, '期初：'.($summary['start_balance'] ?? '0 USDT'));

        // Card 2: 區間淨變動 (Period Net Change)
        $c2X1 = 406;
        $c2W = 304;
        $c2X2 = $c2X1 + $c2W;
        $netChangeVal = (float) ($summary['net_change_val'] ?? 0);
        $isProfitable = $netChangeVal > 0.001;
        $isLoss = $netChangeVal < -0.001;

        $c2Bg = $isProfitable ? '#064e3b' : ($isLoss ? '#450a0a' : '#1e293b');
        $c2Border = $isProfitable ? '#059669' : ($isLoss ? '#dc2626' : '#475569');
        $c2Text = $isProfitable ? '#34d399' : ($isLoss ? '#f87171' : '#cbd5e1');

        $draw->setFillColor($c2Bg);
        $draw->setStrokeColor($c2Border);
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($c2X1, $dashY, $c2X2, $dashY + $dashHeight, 14, 14);

        $draw->setFillColor($isProfitable ? '#a7f3d0' : ($isLoss ? '#fecaca' : '#94a3b8'));
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFontSize(18);
        $draw->setFontWeight(600);
        $draw->annotation($c2X1 + 20, $dashY + 36, '區間損益淨變動');

        $draw->setFillColor($c2Text);
        $draw->setFontSize(30);
        $draw->setFontWeight(800);
        $draw->annotation($c2X1 + 20, $dashY + 82, (string) ($summary['net_change'] ?? '0 USDT'));

        $tagLabel = $isProfitable ? '▲ 盈利' : ($isLoss ? '▼ 虧損' : '平手');
        $roiText = (string) ($summary['roi'] ?? '0.0%');
        $draw->setFontSize(18);
        $draw->setFontWeight(700);
        $draw->annotation($c2X1 + 20, $dashY + 120, $tagLabel." (ROI {$roiText})");

        // Card 3: 最高 / 最低水位 (Peak / Trough)
        $c3X1 = 728;
        $c3W = 304;
        $c3X2 = $c3X1 + $c3W;
        $draw->setFillColor('#0f172a');
        $draw->setStrokeColor('#1e2d45');
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($c3X1, $dashY, $c3X2, $dashY + $dashHeight, 14, 14);

        $draw->setFillColor('#94a3b8');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFontSize(18);
        $draw->setFontWeight(600);
        $draw->annotation($c3X1 + 20, $dashY + 36, '最高 / 最低水位');

        $draw->setFillColor('#34d399');
        $draw->setFontSize(22);
        $draw->setFontWeight(700);
        $draw->annotation($c3X1 + 20, $dashY + 76, '最高：'.($summary['max_watermark'] ?? '0 USDT'));

        $draw->setFillColor('#f87171');
        $draw->setFontSize(22);
        $draw->setFontWeight(700);
        $draw->annotation($c3X1 + 20, $dashY + 118, '最低：'.($summary['min_watermark'] ?? '0 USDT'));

        // Card 4: 結盤戰績 (Record)
        $c4X1 = 1050;
        $c4W = 346;
        $c4X2 = $c4X1 + $c4W;
        $draw->setFillColor('#0f172a');
        $draw->setStrokeColor('#1e2d45');
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($c4X1, $dashY, $c4X2, $dashY + $dashHeight, 14, 14);

        $draw->setFillColor('#94a3b8');
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFontSize(18);
        $draw->setFontWeight(600);
        $draw->annotation($c4X1 + 20, $dashY + 36, '結盤戰績 (Record)');

        $draw->setFillColor('#fbbf24');
        $draw->setFontSize(26);
        $draw->setFontWeight(800);
        $draw->annotation($c4X1 + 20, $dashY + 78, ($summary['won_count'] ?? 0).' 勝  '.($summary['lost_count'] ?? 0).' 負');

        $draw->setFillColor('#94a3b8');
        $draw->setFontSize(18);
        $draw->setFontWeight(600);
        $extraParts = [];
        if (($summary['cashout_count'] ?? 0) > 0) {
            $extraParts[] = $summary['cashout_count'].' 兌現';
        }
        if (($summary['void_count'] ?? 0) > 0) {
            $extraParts[] = $summary['void_count'].' 退款';
        }
        $extraStr = $extraParts !== [] ? implode('  ', $extraParts).'｜' : '';
        $draw->annotation($c4X1 + 20, $dashY + 120, $extraStr.'勝率 '.($summary['win_rate'] ?? '0%'));

        // 5. Main Bar Chart Container
        $cardWidth = 1352;
        $left = 44;
        $chartBoxY = $dashY + $dashHeight + 24;

        if ($isEmpty) {
            $emptyHeight = 260;
            $draw->setFillColor('#0f172a');
            $draw->setStrokeColor('#1e2d45');
            $draw->setStrokeWidth(1.5);
            $draw->roundRectangle($left, $chartBoxY, $left + $cardWidth, $chartBoxY + $emptyHeight, 16, 16);

            $draw->setFillColor('#94a3b8');
            $draw->setStrokeColor('none');
            $draw->setFontSize(32);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($left + (int) round($cardWidth / 2), $chartBoxY + 110, '該區間內尚無結盤之體育注單');

            $draw->setFillColor('#64748b');
            $draw->setFontSize(22);
            $draw->setFontWeight(500);
            $draw->annotation($left + (int) round($cardWidth / 2), $chartBoxY + 160, '資金水位保持為 '.($summary['current_balance'] ?? '0 USDT'));
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            $image->drawImage($draw);
            $draw->clear();

            return $image;
        }

        $chartBoxHeight = 670;
        $draw->setFillColor('#0c1322');
        $draw->setStrokeColor('#1e2d45');
        $draw->setStrokeWidth(1.5);
        $draw->roundRectangle($left, $chartBoxY, $left + $cardWidth, $chartBoxY + $chartBoxHeight, 16, 16);

        // Chart Box Header Title & Legend
        $aggregation = $data['aggregation'] ?? 'bet';
        if ($aggregation === 'daily') {
            $chartTitle = '📊 每日收盤資金水位走勢（USDT）';
        } elseif (count($bars) > 30) {
            $chartTitle = '📊 資金水位變化走勢（USDT・顯示近 30 筆結盤）';
        } else {
            $chartTitle = '📊 資金水位變化走勢（USDT）';
        }

        $draw->setFillColor('#f8fafc');
        $draw->setStrokeColor('none');
        $draw->setFontSize(22);
        $draw->setFontWeight(700);
        $draw->annotation($left + 24, $chartBoxY + 38, $chartTitle);

        // Legend Pills
        if ($aggregation === 'daily') {
            $legendItems = [
                ['color' => '#10b981', 'label' => '當日盈利'],
                ['color' => '#ef4444', 'label' => '當日虧損'],
                ['color' => '#64748b', 'label' => '退款/持平'],
            ];
            $legendX = $left + $cardWidth - 320;
            foreach ($legendItems as $item) {
                $draw->setFillColor($item['color']);
                $draw->roundRectangle($legendX, $chartBoxY + 22, $legendX + 14, $chartBoxY + 36, 3, 3);
                $draw->setFillColor('#94a3b8');
                $draw->setFontSize(16);
                $draw->setFontWeight(600);
                $draw->annotation($legendX + 20, $chartBoxY + 34, $item['label']);
                $legendX += 100;
            }
        } else {
            $hasPendingBar = false;
            foreach ($bars as $b) {
                if (($b['status'] ?? '') === 'pending') {
                    $hasPendingBar = true;
                    break;
                }
            }

            $legendItems = [
                ['color' => '#10b981', 'label' => '獲勝'],
                ['color' => '#ef4444', 'label' => '未中獎'],
                ['color' => '#f59e0b', 'label' => '兌現'],
                ['color' => '#64748b', 'label' => '退款'],
            ];
            if ($hasPendingBar) {
                $legendItems[] = ['color' => '#38bdf8', 'label' => '待結算'];
            }

            $legendWidth = count($legendItems) * 82;
            $legendX = $left + $cardWidth - $legendWidth - 10;
            foreach ($legendItems as $item) {
                $draw->setFillColor($item['color']);
                $draw->roundRectangle($legendX, $chartBoxY + 22, $legendX + 14, $chartBoxY + 36, 3, 3);
                $draw->setFillColor('#94a3b8');
                $draw->setFontSize(16);
                $draw->setFontWeight(600);
                $draw->annotation($legendX + 20, $chartBoxY + 34, $item['label']);
                $legendX += 82;
            }
        }

        // Plot Dimensions
        $plotLeft = $left + 90;
        $plotRight = $left + $cardWidth - 28;
        $plotWidth = $plotRight - $plotLeft;
        $plotTop = $chartBoxY + 70;
        $plotBottom = $chartBoxY + 540;
        $plotHeight = $plotBottom - $plotTop;

        // Compute Y Min and Y Max for Plot Scale
        $balanceValues = array_map(fn (array $b): float => (float) ($b['balance'] ?? 0), $bars);
        if (isset($summary['max_watermark_val'])) {
            $balanceValues[] = (float) $summary['max_watermark_val'];
        }
        if (isset($summary['min_watermark_val'])) {
            $balanceValues[] = (float) $summary['min_watermark_val'];
        }
        $minVal = min($balanceValues);
        $maxVal = max($balanceValues);
        $valRange = $maxVal - $minVal;
        if ($valRange < 1.0) {
            $valRange = max(10.0, $maxVal * 0.1);
        }
        $yFloor = max(0.0, floor(($minVal - $valRange * 0.15) / 5) * 5);
        $yCeil = ceil(($maxVal + $valRange * 0.18) / 5) * 5;
        if ($yCeil <= $yFloor) {
            $yCeil = $yFloor + 50.0;
        }

        // Draw Horizontal Gridlines & Y-Axis Labels (5 levels)
        $steps = 4;
        for ($s = 0; $s <= $steps; $s++) {
            $stepVal = $yFloor + (($yCeil - $yFloor) / $steps) * $s;
            $gridY = $plotBottom - (int) round(($s / $steps) * $plotHeight);

            // Gridline
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1.0);
            $draw->line($plotLeft, $gridY, $plotRight, $gridY);

            // Y-Axis label
            $draw->setStrokeColor('none');
            $draw->setFillColor('#64748b');
            $draw->setFontSize(14);
            $draw->setFontWeight(600);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $draw->annotation($plotLeft - 12, $gridY + 5, number_format($stepVal, 0, '.', ','));
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        }

        // Render Bars
        // If there are many bars, cap at 30 most recent for clear display
        $displayBars = count($bars) > 30 ? array_slice($bars, -30) : $bars;
        $barCount = count($displayBars);
        $slotWidth = $plotWidth / $barCount;
        $barWidth = min(56, max(12, (int) round($slotWidth * 0.72)));

        foreach ($displayBars as $idx => $b) {
            $bVal = (float) ($b['balance'] ?? 0);
            $bRatio = ($bVal - $yFloor) / ($yCeil - $yFloor);
            $bRatio = max(0.02, min(1.0, $bRatio));
            $bHeight = (int) round($bRatio * $plotHeight);

            $centerX = $plotLeft + (int) round(($idx + 0.5) * $slotWidth);
            $bX1 = $centerX - (int) round($barWidth / 2);
            $bX2 = $bX1 + $barWidth;
            $bY1 = $plotBottom - $bHeight;
            $bY2 = $plotBottom;

            $status = $b['status'] ?? 'pending';
            $theme = match ($status) {
                'won' => ['bar' => '#10b981', 'border' => '#34d399', 'text' => '#34d399'],
                'lost' => ['bar' => '#dc2626', 'border' => '#f87171', 'text' => '#f87171'],
                'cashout' => ['bar' => '#d97706', 'border' => '#fbbf24', 'text' => '#fbbf24'],
                'flat' => ['bar' => '#1e293b', 'border' => '#334155', 'text' => '#64748b'],
                'pending' => ['bar' => '#1e293b', 'border' => '#38bdf8', 'text' => '#38bdf8'],
                default => ['bar' => '#475569', 'border' => '#94a3b8', 'text' => '#cbd5e1'],
            };

            // Bar shape
            $draw->setFillColor($theme['bar']);
            $draw->setStrokeColor($theme['border']);
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle($bX1, $bY1, $bX2, $bY2, 5, 5);

            // Labels above bar:
            // 1. Balance Value
            $draw->setStrokeColor('none');
            $draw->setFillColor('#f8fafc');
            $draw->setFontSize($barCount > 20 ? 11 : 14);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);

            $balLabel = $barCount > 18
                ? (string) round($bVal)
                : (string) ($b['balance_formatted'] ?? round($bVal));

            $draw->annotation($centerX, $bY1 - 20, $balLabel);

            // 2. Profit badge/text above bar
            $draw->setFillColor($theme['text']);
            $draw->setFontSize($barCount > 20 ? 11 : 13);
            $draw->setFontWeight(800);
            $draw->annotation($centerX, $bY1 - 6, (string) ($b['profit_formatted'] ?? ''));

            // Labels below bar (X-axis):
            // Time / Summary (e.g. 15:30 or "3 筆")
            $draw->setFillColor('#94a3b8');
            $draw->setFontSize($barCount > 20 ? 11 : 14);
            $draw->setFontWeight(600);
            $draw->annotation($centerX, $plotBottom + 24, (string) ($b['settled_time'] ?? ''));

            // Date (e.g. 09/04)
            $draw->setFillColor('#64748b');
            $draw->setFontSize($barCount > 20 ? 10 : 13);
            $draw->setFontWeight(500);
            $draw->annotation($centerX, $plotBottom + 46, (string) ($b['settled_date'] ?? ''));

            // Bet / Day Index (e.g. #1)
            $draw->setFillColor('#475569');
            $draw->setFontSize(11);
            $draw->setFontWeight(600);
            $indexLabel = isset($b['index_label']) ? (string) $b['index_label'] : '#'.$b['index'];
            $draw->annotation($centerX, $plotBottom + 68, $indexLabel);

            $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        }

        $image->drawImage($draw);
        $draw->clear();

        return $image;
    }
}
