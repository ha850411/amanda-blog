<?php

namespace App\Services\LineSchedule\Renderers;

use Imagick;
use ImagickDraw;
use Throwable;

class BetsRenderer extends AbstractImageRenderer
{
    private const BET_CARDS_TOP = 150;

    private const BET_CARD_HEADER_HEIGHT = 158;

    private const LEG_BOX_HEIGHT = 146;

    private const LEG_GAP = 12;

    private const BET_CARD_BOTTOM_PAD = 12;

    private const BET_CARD_GAP = 20;

    private const CANVAS_BOTTOM_PADDING = 30;

    /**
     * @param  array{
     *     type?: string,
     *     title: string,
     *     subtitle: string,
     *     total_count?: int,
     *     total_staked?: string,
     *     balance_formatted?: string,
     *     bets: array<int, array<string, mixed>>
     * }  $data
     */
    public function render(array $data): Imagick
    {
        $bets = $data['bets'] ?? [];
        $canvasHeight = $this->betsCanvasHeight($bets);
        $image = new Imagick;
        $image->newImage(self::CANVAS_WIDTH, $canvasHeight, '#090d16', 'png');
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);

        $draw = new ImagickDraw;
        $draw->setFont($this->fontPath());
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

            if (! empty($bet['created_at_formatted'])) {
                $draw->setFillColor('#64748b');
                $draw->setFontSize(22);
                $draw->setFontWeight(500);
                $draw->annotation($currentLeftX, $y + 42, $bet['created_at_formatted']);
            }

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

            $draw->setStrokeColor('#1e2d45');
            $draw->setStrokeWidth(1);
            $draw->line($statsX1 + (int) round($colW), $y + 78, $statsX1 + (int) round($colW), $y + 134);
            $draw->line($statsX1 + (int) round($colW * 2), $y + 78, $statsX1 + (int) round($colW * 2), $y + 134);

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

            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(18);
            $draw->setFontWeight(500);
            $draw->annotation($statsX1 + (int) round($colW * 1.5), $y + 95, '總賠率');

            $draw->setFillColor('#38bdf8');
            $draw->setFontSize(28);
            $draw->setFontWeight(800);
            $draw->annotation($statsX1 + (int) round($colW * 1.5), $y + 131, (string) $bet['multiplier_formatted'].'x');

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

                $draw->setFillColor('#162238');
                $draw->setStrokeColor('#233554');
                $draw->setStrokeWidth(1.2);
                $draw->roundRectangle($x + 22, $legY, $x + $cardWidth - 22, $legY + self::LEG_BOX_HEIGHT, 10, 10);

                if ($isParlay) {
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

                $draw->setFillColor('#f8fafc');
                $draw->setStrokeColor('none');
                $draw->setStrokeWidth(0);
                $draw->setFontSize(32);
                $draw->setFontWeight(800);
                $matchName = (string) ($leg['match_name'] ?? '');
                $fittedMatch = $this->fitText($image, $draw, $matchName, 1230, 22);
                $draw->annotation($x + 36, $legY + 80, $fittedMatch);

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
}
