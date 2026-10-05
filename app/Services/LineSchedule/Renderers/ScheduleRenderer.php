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
     * @param  array{title: string, subtitle: string}  $data
     */
    private function drawHeader(Imagick $image, ImagickDraw $draw, array $data, int $matchCount): void
    {
        // Top breadcrumb tag
        $this->scheduleText($image, $draw, 46, 33, 'MATCHDAY  /  賽程總覽 · 戰績由新到舊', 14, '#64748b', 600);

        // Main Title
        $this->scheduleText($image, $draw, 44, 84, $data['title'], 38, '#f8fafc', 700, 1020);

        // Subtitle
        $this->scheduleText($image, $draw, 46, 123, $data['subtitle'], 19, '#94a3b8', 400, 950);

        // Right side: Match Count Badge (Clean, prominent pill instead of redundant duplicate text)
        $badgeText = $matchCount.' 場賽程';
        $draw->setFontSize(16);
        $draw->setFontWeight(700);
        $metrics = $image->queryFontMetrics($draw, $badgeText);
        $badgeW = (int) round($metrics['textWidth']) + 28;
        $badgeX = 1396 - $badgeW;
        $this->scheduleBox($draw, $badgeX, 58, $badgeW, 32, '#1e293b', '#334155', 16);
        $this->scheduleRawText($draw, $badgeX + (int) round($badgeW / 2), 80, $badgeText, 16, '#f1f5f9', 700, Imagick::ALIGN_CENTER);

        // Minimalist Result Legend (勝 / 敗)
        $legends = ['W', 'L'];
        foreach ($legends as $i => $result) {
            $theme = ImageTheme::resultTheme($result);
            $legendX = 1250 + $i * 72;
            $this->scheduleBox($draw, $legendX, 104, 62, 26, $theme['bg'], $theme['border'], 6);
            $this->scheduleRawText($draw, $legendX + 31, 122, $theme['label'], 14, $theme['text'], 700, Imagick::ALIGN_CENTER);
        }
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
        $cardBorder = $isLive ? '#b91c1c' : ($theme['card_border'] ?? '#1e293b');
        $cardWidth = 1352;
        $cardHeight = self::CARD_HEIGHT;

        // 1. Card Container & Game Theme Left Accent
        $this->scheduleBox($draw, $x, $y, $cardWidth, $cardHeight, '#111827', $cardBorder, 12);
        $accentWidth = 4;
        $accentColor = $theme['accent'];
        $this->scheduleBox($draw, $x + 1, $y + 10, $accentWidth, $cardHeight - 20, $accentColor, $accentColor, 2);

        // 2. Section 1 (Left): Match Information, Scoreboard & H2H (Width: 466)
        $s1W = 466;
        $this->drawMatchSection($image, $draw, $x + 16, $y + 10, $s1W, $cardHeight - 20, $match, $game, $theme, $isLive, $iconsToDraw);

        // Divider between Section 1 and Section 2
        $draw->setStrokeColor('#1e293b');
        $draw->setStrokeWidth(1);
        $draw->line($x + 16 + $s1W + 11, $y + 12, $x + 16 + $s1W + 11, $y + $cardHeight - 12);

        // 3. Section 2 & 3: Recent Forms for Team 1 and Team 2 (Width: 406 each)
        $formW = 406;
        $form1X = $x + 16 + $s1W + 22;
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
        $badgeW = $hasIcon ? 74 : 54;
        $this->scheduleBox($draw, $x, $y + 2, $badgeW, 24, $theme['badge_bg'], $theme['accent'], 6);
        if ($hasIcon) {
            $iconsToDraw[] = [
                'path' => $iconPath,
                'x' => $x + 4,
                'y' => $y + 5,
                'size' => 18,
            ];
            $this->scheduleText($image, $draw, $x + 28, $y + 19, $theme['label'], 13, $theme['badge_text'], 700, 42);
        } else {
            $this->scheduleText($image, $draw, $x + 8, $y + 19, $theme['label'], 13, $theme['badge_text'], 700, 44);
        }

        $curX = $x + $badgeW + 6;
        $format = ImageTheme::formatTheme($match['format'] ?? null);
        $formatLabel = (string) ($format['label'] ?? '');
        $hasDistinctFormat = $formatLabel !== '' && strtoupper($formatLabel) !== strtoupper($theme['label']);
        if ($hasDistinctFormat) {
            $this->scheduleBox($draw, $curX, $y + 2, 44, 24, $format['bg'], $format['border'], 6);
            $this->scheduleText($image, $draw, $curX + 6, $y + 19, $formatLabel, 12, $format['text'], 700, 36);
            $curX += 50;
        }

        if ($isLive) {
            $this->scheduleBox($draw, $curX, $y + 2, 66, 24, '#300a14', '#e11d48', 6);
            $draw->setFillColor('#ef4444');
            $draw->setStrokeColor('none');
            $draw->circle($curX + 11, $y + 14, $curX + 13.5, $y + 14);
            $this->scheduleText($image, $draw, $curX + 19, $y + 19, '滾球中', 12, '#fecdd3', 700, 44);
            $curX += 72;

            if (! empty($match['series_score'])) {
                $seriesText = '局數 '.$match['series_score'];
                $this->scheduleBox($draw, $curX, $y + 2, 64, 24, '#1e1427', '#6b21a8', 6);
                $this->scheduleText($image, $draw, $curX + 6, $y + 19, $seriesText, 11, '#e9d5ff', 700, 54);
                $curX += 70;
            }
        } else {
            $status = (string) ($match['status_label'] ?? '');
            if ($status !== '') {
                $this->scheduleText($image, $draw, $curX + 2, $y + 19, $status, 13, '#94a3b8', 600, 52);
                $curX += 54;
            }
        }

        $this->scheduleText($image, $draw, $curX + 2, $y + 19, (string) ($match['start_time'] ?? ''), 17, '#f8fafc', 700, 75);

        // Tournament name on right (dynamically fitted based on available width)
        $availableTourW = max(80, ($x + $w) - ($curX + 64));
        $tourText = $this->fitText($image, $draw, (string) ($match['tournament'] ?? ''), $availableTourW, 11);
        $this->scheduleRawText($draw, $x + $w, $y + 19, $tourText, 13, '#94a3b8', 500, Imagick::ALIGN_RIGHT);

        // Scoreboard (Line 2: Team 1, Line 3: Team 2)
        [$score1, $score2] = $this->parseScores($match['score'] ?? null);

        $price1 = $match['odds']['team1']['price'] ?? null;
        $price2 = $match['odds']['team2']['price'] ?? null;
        $isFavored1 = $price1 !== null && $price2 !== null && $price1 < $price2;
        $isFavored2 = $price2 !== null && $price1 !== null && $price2 < $price1;

        $abbr1 = $game === 'mlb' ? '客隊' : TeamAbbreviation::get((string) $match['team1']);
        $abbr2 = $game === 'mlb' ? '主隊' : TeamAbbreviation::get((string) $match['team2']);

        // Team 1 Row (y + 34, height 40)
        $tBox1Y = $y + 34;
        $this->scheduleBox($draw, $x, $tBox1Y, $w, 40, '#0c1424', '#1d2c44', 6);

        // Monogram / Avatar Tag on Left of Team Name
        $this->scheduleBox($draw, $x + 8, $tBox1Y + 9, 38, 22, '#1e293b', '#334155', 5);
        $this->scheduleRawText($draw, $x + 27, $tBox1Y + 25, $abbr1, 11, '#cbd5e1', 700, Imagick::ALIGN_CENTER);

        // Team Name
        $this->scheduleText($image, $draw, $x + 52, $tBox1Y + 26, (string) $match['team1'], 17, '#f8fafc', 700, 240);

        // Odds on the right / Score
        if ($isLive && $score1 !== null) {
            $this->scheduleRawText($draw, $x + $w - 14, $tBox1Y + 28, $score1, 22, '#ef4444', 700, Imagick::ALIGN_RIGHT);
        } elseif ($score1 !== null) {
            $this->scheduleRawText($draw, $x + $w - 14, $tBox1Y + 27, $score1, 20, '#f8fafc', 700, Imagick::ALIGN_RIGHT);
        } else {
            // Pre-match Odds Badge on the right
            $role1 = $game === 'mlb' ? '客隊 ' : '';
            $oddsLabel1 = $role1.'獨贏 '.($price1 === null ? '—' : sprintf('%.2f', $price1));
            $oddsBg1 = $isFavored1 ? '#082f49' : '#131e33';
            $oddsBorder1 = $isFavored1 ? '#0284c7' : '#25354e';
            $oddsText1 = $isFavored1 ? '#38bdf8' : '#94a3b8';
            $pillW1 = 96;
            $this->scheduleBox($draw, $x + $w - $pillW1 - 8, $tBox1Y + 8, $pillW1, 24, $oddsBg1, $oddsBorder1, 5);
            $this->scheduleRawText($draw, $x + $w - 8 - (int) ($pillW1 / 2), $tBox1Y + 24, $oddsLabel1, 12, $oddsText1, $isFavored1 ? 700 : 500, Imagick::ALIGN_CENTER);
        }

        // Team 2 Row (y + 78, height 40)
        $tBox2Y = $tBox1Y + 44;
        $this->scheduleBox($draw, $x, $tBox2Y, $w, 40, '#0c1424', '#1d2c44', 6);

        // Monogram / Avatar Tag on Left of Team Name
        $this->scheduleBox($draw, $x + 8, $tBox2Y + 9, 38, 22, '#1e293b', '#334155', 5);
        $this->scheduleRawText($draw, $x + 27, $tBox2Y + 25, $abbr2, 11, '#cbd5e1', 700, Imagick::ALIGN_CENTER);

        // Team Name
        $this->scheduleText($image, $draw, $x + 52, $tBox2Y + 26, (string) $match['team2'], 17, '#f8fafc', 700, 240);

        // Odds on the right / Score
        if ($isLive && $score2 !== null) {
            $this->scheduleRawText($draw, $x + $w - 14, $tBox2Y + 28, $score2, 22, '#ef4444', 700, Imagick::ALIGN_RIGHT);
        } elseif ($score2 !== null) {
            $this->scheduleRawText($draw, $x + $w - 14, $tBox2Y + 27, $score2, 20, '#f8fafc', 700, Imagick::ALIGN_RIGHT);
        } else {
            // Pre-match Odds Badge on the right
            $role2 = $game === 'mlb' ? '主隊 ' : '';
            $oddsLabel2 = $role2.'獨贏 '.($price2 === null ? '—' : sprintf('%.2f', $price2));
            $oddsBg2 = $isFavored2 ? '#082f49' : '#131e33';
            $oddsBorder2 = $isFavored2 ? '#0284c7' : '#25354e';
            $oddsText2 = $isFavored2 ? '#38bdf8' : '#94a3b8';
            $pillW2 = 96;
            $this->scheduleBox($draw, $x + $w - $pillW2 - 8, $tBox2Y + 8, $pillW2, 24, $oddsBg2, $oddsBorder2, 5);
            $this->scheduleRawText($draw, $x + $w - 8 - (int) ($pillW2 / 2), $tBox2Y + 24, $oddsLabel2, 12, $oddsText2, $isFavored2 ? 700 : 500, Imagick::ALIGN_CENTER);
        }

        // Line 4: H2H footer
        $h2hY = $tBox2Y + 48;
        $this->scheduleBox($draw, $x, $h2hY, $w, 26, '#090f1c', '#182438', 5);

        $h2h = $match['h2h'] ?? null;
        if (! is_array($h2h) || empty($h2h['series'])) {
            $this->scheduleRawText($draw, $x + 10, $h2hY + 17, '⚔️  雙方近兩年無正式交手紀錄', 12, '#64748b', 500);
        } else {
            $w1 = (int) ($h2h['team1_wins'] ?? 0);
            $w2 = (int) ($h2h['team2_wins'] ?? 0);
            $sample = (int) ($h2h['sample_size'] ?? ($w1 + $w2));
            $t1Abbr = $abbr1;
            $t2Abbr = $abbr2;

            $last = $h2h['series'][0] ?? null;
            $lastTxt = '';
            if ($last) {
                $lastS1 = $last['team1_score'] ?? 0;
                $lastS2 = $last['team2_score'] ?? 0;
                $lastTxt = " (近場 {$lastS1}:{$lastS2})";
            }
            $h2hText = "⚔️  交手 {$sample} 次 · {$t1Abbr} {$w1}勝 - {$w2}勝 {$t2Abbr}{$lastTxt}";
            $this->scheduleRawText($draw, $x + 10, $h2hY + 17, $h2hText, 12, '#cbd5e1', 600);
        }
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
        // Header line: Team Name + Summary badge
        $this->scheduleText($image, $draw, $x + 2, $y + 13, $name.' · 近 5 場', 14, '#f8fafc', 700, 260);

        // Win-Loss Summary Label (e.g. 3 勝 2 敗)
        $formLabel = RecentForm::label($form);
        $this->scheduleRawText($draw, $x + $w - 4, $y + 13, $formLabel, 12, '#94a3b8', 400, Imagick::ALIGN_RIGHT);

        $boxY = $y + 18;
        $boxH = 138;
        $this->scheduleBox($draw, $x, $boxY, $w, $boxH, '#0c1424', '#1d2c44', 6);

        $matches = array_slice($form['matches'] ?? [], 0, 5);
        if ($matches === []) {
            $label = ($form['sample_size'] ?? 0) > 0 ? '對戰明細暫無資料' : $formLabel;
            $this->scheduleRawText($draw, $x + (int) ($w / 2), $boxY + 72, $label, 13, '#64748b', 500, Imagick::ALIGN_CENTER);

            return;
        }

        foreach ($matches as $i => $rec) {
            $rowY = (int) round($boxY + 3 + $i * 26.5);
            $theme = ImageTheme::resultTheme($rec['result'] ?? '');

            // Alternating clean row background
            $rowBg = $i % 2 === 0 ? '#121b2d' : '#0c1424';
            $this->scheduleBox($draw, $x + 4, $rowY, $w - 8, 24, $rowBg, $rowBg, 4);

            // Pill: Win/Loss score capsule
            $pillW = 72;
            $this->scheduleBox($draw, $x + 6, $rowY + 2, $pillW, 20, $theme['bg'], $theme['border'], 4);
            $score = isset($rec['team_score'], $rec['opponent_score']) ? $rec['team_score'].':'.$rec['opponent_score'] : '—';
            $this->scheduleRawText($draw, $x + 6 + (int) ($pillW / 2), $rowY + 16, $theme['label'].'  '.$score, 11, $theme['text'], 700, Imagick::ALIGN_CENTER);

            // Opponent name
            $this->scheduleText($image, $draw, $x + 86, $rowY + 16, 'vs '.$rec['opponent'], 13, '#f8fafc', 600, 200);

            // Date & format (Year removed for mobile space: 10/03 instead of 2026/10/03)
            $date = $this->formatRecentDate($rec['date'] ?? null);
            $meta = $date.(isset($rec['format']) ? ' · '.$rec['format'] : '');
            $this->scheduleRawText($draw, $x + $w - 10, $rowY + 16, $meta, 11, '#94a3b8', 400, Imagick::ALIGN_RIGHT);
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
