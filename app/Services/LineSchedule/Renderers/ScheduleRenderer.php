<?php

namespace App\Services\LineSchedule\Renderers;

use App\Services\LineSchedule\Support\ImageTheme;
use App\Services\LineSchedule\Support\TeamAbbreviation;
use App\Services\RecentForm;
use Imagick;
use ImagickDraw;
use Throwable;

class ScheduleRenderer extends AbstractImageRenderer
{
    private const CARD_HEIGHT = 176;

    private const CARD_GAP = 12;

    private const CARDS_TOP = 160;

    private const CANVAS_BOTTOM_PADDING = 30;

    /**
     * @param  array{
     *     title: string,
     *     subtitle: string,
     *     game?: ?string,
     *     matches: array<int, array<string, mixed>>
     * }  $data
     */
    public function render(array $data): Imagick
    {
        $matches = $data['matches'];
        $image = new Imagick;
        $image->newImage(self::CANVAS_WIDTH, $this->canvasHeight($matches), '#090d16', 'png');
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);

        $draw = new ImagickDraw;
        $draw->setFont($this->fontPath());
        $draw->setTextAntialias(true);

        $this->drawHeader($image, $draw, $data, count($matches));

        $x = 44;
        $y = self::CARDS_TOP;
        $iconsToDraw = [];

        foreach ($matches as $match) {
            $this->drawScheduleCard($image, $draw, $x, $y, $match, $data['game'] ?? null, $iconsToDraw);
            $y += self::CARD_HEIGHT + self::CARD_GAP;
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

    /**
     * @param  array{title: string, subtitle: string, game?: ?string, matches?: array<int, array<string, mixed>>}  $data
     */
    private function drawHeader(Imagick $image, ImagickDraw $draw, array $data, int $matchCount): void
    {
        $game = $data['game'] ?? null;
        $headerTheme = ($game !== null && $game !== 'all')
            ? ImageTheme::forGame($game)
            : ['accent' => '#38bdf8', 'badge_bg' => '#0284c7', 'badge_text' => '#ffffff', 'card_border' => '#38bdf8', 'label' => 'ALL'];

        // Top breadcrumb tag with accent indicator bar
        $this->scheduleBox($draw, 44, 20, 4, 15, $headerTheme['accent'], $headerTheme['accent'], 2);
        $this->scheduleText($image, $draw, 56, 33, 'MATCHDAY SCHEDULE  /  賽程盤口與近況分析 · 戰績由新到舊', 14, '#94a3b8', 700);

        // Main Title (44px bold to match !bet / !record visual weight)
        $this->scheduleText($image, $draw, 44, 84, $data['title'], 44, '#f8fafc', 800, 980);

        // Smart Subtitle: Live / Upcoming breakdown + optional warning
        $matches = $data['matches'] ?? [];
        $liveCount = count(array_filter($matches, fn (array $m): bool => (bool) ($m['is_live'] ?? false)));
        $upcomingCount = max(0, $matchCount - $liveCount);

        $rawSubtitle = (string) ($data['subtitle'] ?? '');
        $warningText = '';
        if (str_contains($rawSubtitle, '暫時無法取得')) {
            $parts = explode('｜', $rawSubtitle);
            $warningParts = array_filter($parts, fn (string $p): bool => str_contains($p, '暫時無法取得'));
            if ($warningParts !== []) {
                $warningText = implode('｜', $warningParts);
            }
        }

        $subX = 46;
        if ($liveCount > 0) {
            $livePillText = "{$liveCount} 場滾球中";
            $draw->setFontSize(14);
            $draw->setFontWeight(800);
            $liveMetrics = $image->queryFontMetrics($draw, $livePillText);
            $livePillW = (int) round($liveMetrics['textWidth']) + 36;
            $this->scheduleBox($draw, 44, 102, $livePillW, 28, '#3b0814', '#e11d48', 14, 1.2);
            $draw->setFillColor('#ef4444');
            $draw->setStrokeColor('none');
            $draw->circle(58, 116, 62, 116);
            $this->scheduleRawText($draw, 68, 121, $livePillText, 14, '#fecdd3', 800, Imagick::ALIGN_LEFT);
            $subX = 44 + $livePillW + 14;
        }

        $statusSummary = $liveCount > 0
            ? "台灣時間  ·  {$upcomingCount} 場未開賽  ·  獨贏盤口與近 5 場戰績"
            : "台灣時間  ·  共 {$matchCount} 場焦點賽事  ·  獨贏盤口與近 5 場戰績";

        if ($warningText !== '') {
            $statusSummary .= '  ｜  ⚠ '.$warningText;
        }

        $this->scheduleText($image, $draw, $subX, 122, $statusSummary, 18, $warningText !== '' ? '#fbbf24' : '#94a3b8', 500, 1140 - $subX);

        // Right side: Prominent Match Count Pill (aligned with BetsRenderer / BetHistoryRenderer)
        $badgeText = $matchCount.' 場賽程';
        $draw->setFontSize(22);
        $draw->setFontWeight(800);
        $metrics = $image->queryFontMetrics($draw, $badgeText);
        $badgeW = (int) round($metrics['textWidth']) + 36;
        $badgeX = 1396 - $badgeW;
        $countFill = ($game !== null && $game !== 'all') ? $headerTheme['badge_bg'] : '#0284c7';
        $countBorder = ($game !== null && $game !== 'all') ? $headerTheme['accent'] : '#38bdf8';
        $countTextCol = ($game !== null && $game !== 'all') ? $headerTheme['badge_text'] : '#ffffff';
        $this->scheduleBox($draw, $badgeX, 36, $badgeW, 48, $countFill, $countBorder, 24, 1.5);
        $this->scheduleRawText($draw, $badgeX + (int) round($badgeW / 2), 68, $badgeText, 22, $countTextCol, 800, Imagick::ALIGN_CENTER);

        // Result Legend (戰績圖例: 勝 / 敗)
        $this->scheduleRawText($draw, 1236, 119, '戰績圖例', 13, '#64748b', 600, Imagick::ALIGN_RIGHT);
        $legends = ['W', 'L'];
        foreach ($legends as $i => $result) {
            $theme = ImageTheme::resultTheme($result);
            $legendX = 1250 + $i * 76;
            $this->scheduleBox($draw, $legendX, 99, 70, 28, $theme['bg'], $theme['border'], 6, 1.2);
            $this->scheduleRawText($draw, $legendX + 35, 119, $theme['label'], 15, $theme['text'], 800, Imagick::ALIGN_CENTER);
        }

        // Subtle Header Bottom Divider Line
        $draw->setStrokeColor('#1e293b');
        $draw->setStrokeWidth(1);
        $draw->line(44, 144, 1396, 144);
    }

    /**
     * @param  array<int, array{path: string, x: int, y: int, size: int}>  $iconsToDraw
     */
    private function drawScheduleCard(
        Imagick $image,
        ImagickDraw $draw,
        int $x,
        int $y,
        array $match,
        ?string $defaultGame,
        array &$iconsToDraw
    ): void {
        $game = $match['game'] ?? $defaultGame ?? null;
        $theme = ImageTheme::forGame($game);
        $isLive = (bool) ($match['is_live'] ?? false);
        $cardBorder = $isLive ? '#dc2626' : ($theme['card_border'] ?? '#1e293b');
        $cardWidth = 1352;
        $cardHeight = self::CARD_HEIGHT;

        // 1. Card Container & Game Theme Left Accent
        $this->scheduleBox($draw, $x, $y, $cardWidth, $cardHeight, '#111827', $cardBorder, 14, $isLive ? 1.6 : 1.2);
        $accentWidth = 5;
        $accentColor = $isLive ? '#ef4444' : $theme['accent'];
        $this->scheduleBox($draw, $x + 2, $y + 12, $accentWidth, $cardHeight - 24, $accentColor, $accentColor, 2);

        // 2. Section 1 (Left): Match Information, Scoreboard & H2H (Width: 468)
        $s1W = 468;
        $this->drawMatchSection($image, $draw, $x + 16, $y + 9, $s1W, $cardHeight - 18, $match, $game, $theme, $isLive, $iconsToDraw);

        // Divider between Section 1 and Section 2
        $draw->setStrokeColor('#1e293b');
        $draw->setStrokeWidth(1);
        $draw->line($x + 16 + $s1W + 10, $y + 12, $x + 16 + $s1W + 10, $y + $cardHeight - 12);

        // 3. Section 2 & 3: Recent Forms for Team 1 and Team 2 (Width: 406 each)
        $formW = 406;
        $form1X = $x + 16 + $s1W + 20;
        $form2X = $form1X + $formW + 14;

        $this->drawRecentFormSection($image, $draw, $form1X, $y + 8, $formW, (string) $match['team1'], $match['recent_form']['team1'] ?? null);
        $this->drawRecentFormSection($image, $draw, $form2X, $y + 8, $formW, (string) $match['team2'], $match['recent_form']['team2'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array{accent: string, badge_bg: string, badge_text: string, card_border: string, label: string}  $theme
     * @param  array<int, array{path: string, x: int, y: int, size: int}>  $iconsToDraw
     */
    private function drawMatchSection(
        Imagick $image,
        ImagickDraw $draw,
        int $x,
        int $y,
        int $w,
        int $h,
        array $match,
        ?string $game,
        array $theme,
        bool $isLive,
        array &$iconsToDraw
    ): void {
        // Line 1: Game Badge + Format + Live/Status + Start Time + Tournament
        $iconPath = $this->iconPathForGame($game);
        $hasIcon = $iconPath !== null;
        $badgeW = $hasIcon ? 76 : 56;
        $this->scheduleBox($draw, $x, $y, $badgeW, 26, $theme['badge_bg'], $theme['accent'], 6, 1.2);
        if ($hasIcon) {
            $iconsToDraw[] = [
                'path' => $iconPath,
                'x' => $x + 5,
                'y' => $y + 4,
                'size' => 18,
            ];
            $this->scheduleText($image, $draw, $x + 28, $y + 18, $theme['label'], 14, $theme['badge_text'], 800, 44);
        } else {
            $this->scheduleRawText($draw, $x + (int) round($badgeW / 2), $y + 18, $theme['label'], 14, $theme['badge_text'], 800, Imagick::ALIGN_CENTER);
        }

        $curX = $x + $badgeW + 6;
        $format = ImageTheme::formatTheme($match['format'] ?? null);
        $formatLabel = (string) ($format['label'] ?? '');
        $hasDistinctFormat = $formatLabel !== '' && strtoupper($formatLabel) !== strtoupper($theme['label']);
        if ($hasDistinctFormat) {
            $this->scheduleBox($draw, $curX, $y, 48, 26, $format['bg'], $format['border'], 6, 1.2);
            $this->scheduleRawText($draw, $curX + 24, $y + 18, $formatLabel, 13, $format['text'], 800, Imagick::ALIGN_CENTER);
            $curX += 54;
        }

        if ($isLive) {
            $this->scheduleBox($draw, $curX, $y, 70, 26, '#3b0814', '#e11d48', 6, 1.2);
            $draw->setFillColor('#ef4444');
            $draw->setStrokeColor('none');
            $draw->circle($curX + 12, $y + 13, $curX + 15, $y + 13);
            $this->scheduleText($image, $draw, $curX + 21, $y + 18, '滾球中', 13, '#fecdd3', 800, 46);
            $curX += 76;

            $timeText = (string) ($match['start_time'] ?? '');
            $this->scheduleText($image, $draw, $curX, $y + 19, $timeText, 16, '#cbd5e1', 700, 72);
            $draw->setFontSize(16);
            $draw->setFontWeight(700);
            $timeW = (int) round($image->queryFontMetrics($draw, $timeText)['textWidth']);
            $curX += $timeW + 8;
        } else {
            $timeText = (string) ($match['start_time'] ?? '');
            $this->scheduleText($image, $draw, $curX + 2, $y + 19, $timeText, 18, '#f8fafc', 800, 88);
            $draw->setFontSize(18);
            $draw->setFontWeight(800);
            $timeW = (int) round($image->queryFontMetrics($draw, $timeText)['textWidth']);
            $curX += $timeW + 10;

            $status = (string) ($match['status_label'] ?? '');
            if ($status !== '') {
                $isDelayed = str_contains($status, '延遲') || str_contains($status, '暫停');
                $stBg = $isDelayed ? '#3d1a04' : '#162032';
                $stBorder = $isDelayed ? '#d97706' : '#293952';
                $stText = $isDelayed ? '#fbbf24' : '#94a3b8';
                $draw->setFontSize(12);
                $draw->setFontWeight(700);
                $stW = (int) round($image->queryFontMetrics($draw, $status)['textWidth']) + 16;
                $this->scheduleBox($draw, $curX, $y + 2, $stW, 22, $stBg, $stBorder, 5);
                $this->scheduleRawText($draw, $curX + (int) round($stW / 2), $y + 17, $status, 12, $stText, 700, Imagick::ALIGN_CENTER);
                $curX += $stW + 6;
            }
        }

        // Tournament name on right (generous width since series score moved to team rows)
        $availableTourW = max(90, ($x + $w) - ($curX + 4));
        $draw->setFontSize(13);
        $draw->setFontWeight(600);
        $tourText = $this->fitText($image, $draw, (string) ($match['tournament'] ?? ''), $availableTourW, 11);
        $this->scheduleRawText($draw, $x + $w, $y + 18, $tourText, (int) $draw->getFontSize(), '#94a3b8', 600, Imagick::ALIGN_RIGHT);

        // Scoreboard (Line 2: Team 1, Line 3: Team 2)
        [$series1, $series2] = $this->parseScores($match['series_score'] ?? null);
        [$score1, $score2] = $this->parseScores($match['score'] ?? null);

        $price1 = $match['odds']['team1']['price'] ?? null;
        $price2 = $match['odds']['team2']['price'] ?? null;
        $hasAnyOdds = $price1 !== null || $price2 !== null;
        $isFavored1 = $price1 !== null && $price2 !== null && $price1 < $price2;
        $isFavored2 = $price2 !== null && $price1 !== null && $price2 < $price1;

        $lead1 = ($series1 !== null && $series2 !== null && (int) $series1 > (int) $series2)
            || ($series1 === null && $score1 !== null && $score2 !== null && (int) $score1 > (int) $score2);
        $lead2 = ($series1 !== null && $series2 !== null && (int) $series2 > (int) $series1)
            || ($series1 === null && $score1 !== null && $score2 !== null && (int) $score2 > (int) $score1);

        $hasHomeAway = in_array($game, ['mlb', 'nba'], true);
        $abbr1 = $hasHomeAway ? '客隊' : TeamAbbreviation::get((string) $match['team1']);
        $abbr2 = $hasHomeAway ? '主隊' : TeamAbbreviation::get((string) $match['team2']);

        $this->drawTeamScoreRow(
            $image,
            $draw,
            $x,
            $y + 32,
            $w,
            (string) $match['team1'],
            $abbr1,
            $hasHomeAway ? '客隊 ' : '',
            $price1,
            $hasAnyOdds,
            $isFavored1,
            $isLive,
            $lead1,
            $series1,
            $score1,
        );

        $this->drawTeamScoreRow(
            $image,
            $draw,
            $x,
            $y + 78,
            $w,
            (string) $match['team2'],
            $abbr2,
            $hasHomeAway ? '主隊 ' : '',
            $price2,
            $hasAnyOdds,
            $isFavored2,
            $isLive,
            $lead2,
            $series2,
            $score2,
        );

        // Line 4: H2H footer (Aligned at y + 126, height 29 -> ends at card y + 164)
        $h2hY = $y + 126;
        $this->scheduleBox($draw, $x, $h2hY, $w, 29, '#09101d', '#17253b', 6);

        $h2h = $match['h2h'] ?? null;
        $hasH2h = is_array($h2h) && ! empty($h2h['series']);

        // Geometric H2H Badge (replaces broken ⚔️ emoji glyph)
        $h2hBadgeBg = $hasH2h ? '#1e1b4b' : '#111a2b';
        $h2hBadgeBorder = $hasH2h ? '#4f46e5' : '#1e293b';
        $h2hBadgeText = $hasH2h ? '#a5b4fc' : '#64748b';
        $this->scheduleBox($draw, $x + 6, $h2hY + 4, 42, 21, $h2hBadgeBg, $h2hBadgeBorder, 4);
        $this->scheduleRawText($draw, $x + 27, $h2hY + 19, 'H2H', 11, $h2hBadgeText, 800, Imagick::ALIGN_CENTER);

        if (! $hasH2h) {
            $emptyLabel = $hasHomeAway ? '暫無交手資料' : '雙方近兩年無正式交手紀錄';
            $this->scheduleRawText($draw, $x + 56, $h2hY + 19, $emptyLabel, 13, '#64748b', 500);
        } else {
            $w1 = (int) ($h2h['team1_wins'] ?? 0);
            $w2 = (int) ($h2h['team2_wins'] ?? 0);
            $sample = (int) ($h2h['sample_size'] ?? ($w1 + $w2));

            $last = $h2h['series'][0] ?? null;
            $lastTxt = '';
            if ($last) {
                $lastS1 = $last['team1_score'] ?? 0;
                $lastS2 = $last['team2_score'] ?? 0;
                $lastTxt = " (近場 {$lastS1}:{$lastS2})";
            }
            $h2hText = "交手 {$sample} 場 · {$abbr1} {$w1}勝 - {$w2}勝 {$abbr2}{$lastTxt}";
            $this->scheduleText($image, $draw, $x + 56, $h2hY + 19, $h2hText, 13, '#e2e8f0', 700, $w - 134);

            // Visual Win-Share Ratio Bar on the right of H2H footer
            $totalWins = $w1 + $w2;
            if ($totalWins > 0) {
                $barW = 62;
                $barH = 7;
                $barX = $x + $w - $barW - 10;
                $barY = $h2hY + 11;

                if ($w1 > 0 && $w2 > 0) {
                    $w1Width = max(8, min($barW - 8, (int) round(($w1 / $totalWins) * $barW)));
                    $this->scheduleBox($draw, $barX, $barY, $w1Width - 1, $barH, '#38bdf8', 'none', 3, 0);
                    $this->scheduleBox($draw, $barX + $w1Width + 1, $barY, $barW - $w1Width - 1, $barH, '#f43f5e', 'none', 3, 0);
                } elseif ($w1 > 0) {
                    $this->scheduleBox($draw, $barX, $barY, $barW, $barH, '#38bdf8', 'none', 3, 0);
                } else {
                    $this->scheduleBox($draw, $barX, $barY, $barW, $barH, '#f43f5e', 'none', 3, 0);
                }
            }
        }
    }

    private function drawTeamScoreRow(
        Imagick $image,
        ImagickDraw $draw,
        int $x,
        int $rowY,
        int $w,
        string $teamName,
        string $abbr,
        string $rolePrefix,
        ?float $price,
        bool $hasAnyOdds,
        bool $isFavored,
        bool $isLive,
        bool $isLeading,
        ?string $seriesScore,
        ?string $mapScore
    ): void {
        $rowH = 42;
        $rowBg = $isLive && $isLeading
            ? '#181426'
            : ($isFavored ? '#0d182b' : '#0b1322');
        $rowBorder = $isLive && $isLeading
            ? '#991b1b'
            : ($isFavored ? '#1e3a5f' : '#18263c');

        $this->scheduleBox($draw, $x, $rowY, $w, $rowH, $rowBg, $rowBorder, 7, $isFavored || ($isLive && $isLeading) ? 1.2 : 1.0);

        // Monogram / Avatar Tag on Left of Team Name
        $abbrBg = $isFavored ? '#172554' : '#1e293b';
        $abbrBorder = $isFavored ? '#2563eb' : '#334155';
        $abbrText = $isFavored ? '#dbeafe' : '#cbd5e1';
        $this->scheduleBox($draw, $x + 8, $rowY + 8, 42, 26, $abbrBg, $abbrBorder, 5);
        $this->scheduleRawText($draw, $x + 29, $rowY + 26, $abbr, 12, $abbrText, 800, Imagick::ALIGN_CENTER);

        // Right-side elements calculation to know max team name width
        $hasScore = $seriesScore !== null || $mapScore !== null;
        if ($hasScore) {
            $rightEdge = $x + $w - 8;

            // Main rightmost score box (Current Map/Game score if available, else Series score)
            $primaryScore = $mapScore ?? $seriesScore ?? '0';
            $scoreBoxW = 44;
            $scoreBoxX = $rightEdge - $scoreBoxW;
            $scoreBg = $isLive ? ($isLeading ? '#450a0a' : '#26121c') : '#1e293b';
            $scoreBorder = $isLive ? ($isLeading ? '#ef4444' : '#7f1d1d') : '#475569';
            $scoreTextCol = $isLive ? ($isLeading ? '#fecaca' : '#f87171') : '#f8fafc';
            $this->scheduleBox($draw, $scoreBoxX, $rowY + 7, $scoreBoxW, 28, $scoreBg, $scoreBorder, 6, 1.2);
            $this->scheduleRawText($draw, $scoreBoxX + (int) ($scoreBoxW / 2), $rowY + 28, $primaryScore, 19, $scoreTextCol, 800, Imagick::ALIGN_CENTER);
            $rightEdge = $scoreBoxX - 6;

            // If both series_score AND map_score exist, show Series Score badge to the left
            if ($seriesScore !== null && $mapScore !== null) {
                $seriesBadgeW = 58;
                $seriesBadgeX = $rightEdge - $seriesBadgeW;
                $this->scheduleBox($draw, $seriesBadgeX, $rowY + 8, $seriesBadgeW, 26, '#1e1b4b', '#6366f1', 5);
                $this->scheduleRawText($draw, $seriesBadgeX + (int) ($seriesBadgeW / 2), $rowY + 26, '大局 '.$seriesScore, 12, '#e0e7ff', 700, Imagick::ALIGN_CENTER);
                $rightEdge = $seriesBadgeX - 6;
            }

            // Also preserve pre-match moneyline odds during live matches when available
            if ($price !== null) {
                $oddsText = sprintf('@%.2f', $price);
                $this->scheduleRawText($draw, $rightEdge - 2, $rowY + 26, $oddsText, 13, $isFavored ? '#38bdf8' : '#64748b', 700, Imagick::ALIGN_RIGHT);
                $rightEdge -= 54;
            }

            $maxNameW = max(120, $rightEdge - ($x + 58) - 6);
        } elseif ($hasAnyOdds) {
            $pillW = 108;
            $pillX = $x + $w - $pillW - 8;
            $oddsLabel = $rolePrefix.'獨贏 '.($price === null ? '—' : sprintf('%.2f', $price));
            $oddsBg = $isFavored ? '#082f49' : '#131e33';
            $oddsBorder = $isFavored ? '#0ea5e9' : '#25354e';
            $oddsText = $isFavored ? '#38bdf8' : '#94a3b8';
            $this->scheduleBox($draw, $pillX, $rowY + 7, $pillW, 28, $oddsBg, $oddsBorder, 6, $isFavored ? 1.4 : 1.0);
            $this->scheduleRawText($draw, $pillX + (int) ($pillW / 2), $rowY + 26, $oddsLabel, 13, $oddsText, $isFavored ? 800 : 600, Imagick::ALIGN_CENTER);
            $maxNameW = $pillX - ($x + 58) - 8;
        } else {
            $pillW = 84;
            $pillX = $x + $w - $pillW - 8;
            $mutedLabel = $rolePrefix !== '' ? trim($rolePrefix).' · 待開盤' : '暫無盤口';
            $this->scheduleBox($draw, $pillX, $rowY + 9, $pillW, 24, '#0f172a', '#1e293b', 5);
            $this->scheduleRawText($draw, $pillX + (int) ($pillW / 2), $rowY + 25, $mutedLabel, 11, '#64748b', 600, Imagick::ALIGN_CENTER);
            $maxNameW = $pillX - ($x + 58) - 8;
        }

        // Team Name
        $this->scheduleText($image, $draw, $x + 58, $rowY + 28, $teamName, 18, '#f8fafc', 700, $maxNameW);
    }

    /**
     * @param  array<string, mixed>|null  $form
     */
    private function drawRecentFormSection(
        Imagick $image,
        ImagickDraw $draw,
        int $x,
        int $y,
        int $w,
        string $name,
        ?array $form
    ): void {
        // Win-Loss Summary Pill on Top Right (e.g. 4 勝 1 敗)
        $formLabel = RecentForm::label($form);
        $summaryTheme = ImageTheme::formSummaryTheme($form);
        $draw->setFontSize(12);
        $draw->setFontWeight(700);
        $labelMetrics = $image->queryFontMetrics($draw, $formLabel);
        $summaryPillW = (int) round($labelMetrics['textWidth']) + 18;
        $summaryPillX = $x + $w - $summaryPillW;
        $this->scheduleBox($draw, $summaryPillX, $y - 5, $summaryPillW, 20, $summaryTheme['bg'], $summaryTheme['border'], 5);
        $this->scheduleRawText($draw, $summaryPillX + (int) round($summaryPillW / 2), $y + 10, $formLabel, 11, $summaryTheme['text'], 700, Imagick::ALIGN_CENTER);

        // Header line: Team Name + 近 5 場
        $maxTitleW = max(140, $summaryPillX - ($x + 2) - 8);
        $this->scheduleText($image, $draw, $x + 2, $y + 11, $name.' · 近 5 場', 15, '#f8fafc', 700, $maxTitleW);

        $boxY = $y + 18;
        $boxH = 138;
        $this->scheduleBox($draw, $x, $boxY, $w, $boxH, '#0b1322', '#18263c', 6);

        $matches = array_slice($form['matches'] ?? [], 0, 5);
        if ($matches === []) {
            for ($i = 0; $i < 5; $i++) {
                $rowY = (int) round($boxY + 3 + $i * 26.5);
                $rowBg = $i % 2 === 0 ? '#0f1829' : '#0b1322';
                $this->scheduleBox($draw, $x + 4, $rowY, $w - 8, 24, $rowBg, $rowBg, 4);
            }
            $label = ($form['sample_size'] ?? 0) > 0 ? '對戰明細暫無資料' : '近期尚無完賽紀錄';
            $this->scheduleBox($draw, $x + (int) (($w - 176) / 2), $boxY + 51, 176, 36, '#111c2f', '#1e2f4d', 8);
            $this->scheduleRawText($draw, $x + (int) ($w / 2), $boxY + 74, $label, 13, '#64748b', 600, Imagick::ALIGN_CENTER);

            return;
        }

        for ($i = 0; $i < 5; $i++) {
            $rowY = (int) round($boxY + 3 + $i * 26.5);
            $rec = $matches[$i] ?? null;

            if ($rec === null) {
                $this->scheduleBox($draw, $x + 4, $rowY, $w - 8, 24, '#0d1525', '#0d1525', 4);
                $this->scheduleBox($draw, $x + 6, $rowY + 2, 74, 20, '#111a2c', '#1a2740', 4);
                $this->scheduleRawText($draw, $x + 43, $rowY + 16, '—', 11, '#334155', 700, Imagick::ALIGN_CENTER);
                if ($i === count($matches)) {
                    $this->scheduleRawText($draw, $x + 88, $rowY + 16, '尚無更多近期對局紀錄', 12, '#475569', 500);
                }

                continue;
            }

            $theme = ImageTheme::resultTheme($rec['result'] ?? '');

            // Alternating clean row background
            $rowBg = $i % 2 === 0 ? '#121c2f' : '#0c1424';
            $this->scheduleBox($draw, $x + 4, $rowY, $w - 8, 24, $rowBg, $rowBg, 4);

            // Pill: Win/Loss score capsule
            $pillW = 74;
            $this->scheduleBox($draw, $x + 6, $rowY + 2, $pillW, 20, $theme['bg'], $theme['border'], 4);
            $score = isset($rec['team_score'], $rec['opponent_score']) ? $rec['team_score'].':'.$rec['opponent_score'] : '—';
            $this->scheduleRawText($draw, $x + 6 + (int) ($pillW / 2), $rowY + 16, $theme['label'].'  '.$score, 12, $theme['text'], 800, Imagick::ALIGN_CENTER);

            // Opponent name
            $this->scheduleText($image, $draw, $x + 88, $rowY + 17, 'vs '.$rec['opponent'], 14, '#f8fafc', 600, 198);

            // Date & format (Year removed for mobile space: 10/03 instead of 2026/10/03)
            $date = $this->formatRecentDate($rec['date'] ?? null);
            $meta = $date.(isset($rec['format']) ? ' · '.$rec['format'] : '');
            $this->scheduleRawText($draw, $x + $w - 10, $rowY + 16, $meta, 12, '#94a3b8', 500, Imagick::ALIGN_RIGHT);
        }
    }

    private function formatRecentDate(?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return '—';
        }

        if (preg_match('/^\d{4}[\/\-](.+)$/', trim($date), $m)) {
            return $m[1];
        }

        return $date;
    }

    /** @return array{?string, ?string} */
    private function parseScores(?string $scoreText): array
    {
        if ($scoreText === null || trim($scoreText) === '') {
            return [null, null];
        }

        $parts = preg_split('/[：:\-]/u', $scoreText);
        if ($parts !== false && count($parts) >= 2) {
            return [trim($parts[0]), trim($parts[1])];
        }

        return [null, null];
    }

    /** @param array<int, array<string, mixed>> $matches */
    private function canvasHeight(array $matches): int
    {
        return self::CARDS_TOP
            + (count($matches) * self::CARD_HEIGHT)
            + (max(0, count($matches) - 1) * self::CARD_GAP)
            + self::CANVAS_BOTTOM_PADDING;
    }
}
