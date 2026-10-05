<?php

namespace App\Services\LineSchedule\Renderers;

use Imagick;
use ImagickDraw;

class BalanceChartRenderer extends AbstractImageRenderer
{
    /**
     * @param  array{
     *     title?: string,
     *     subtitle?: string,
     *     aggregation?: string,
     *     summary?: array<string, mixed>,
     *     current_balance_formatted?: string,
     *     bars: array<int, array<string, mixed>>
     * }  $data
     */
    public function render(array $data): Imagick
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

            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1.0);
            $draw->line($plotLeft, $gridY, $plotRight, $gridY);

            $draw->setStrokeColor('none');
            $draw->setFillColor('#64748b');
            $draw->setFontSize(14);
            $draw->setFontWeight(600);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $draw->annotation($plotLeft - 12, $gridY + 5, number_format($stepVal, 0, '.', ','));
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        }

        // Render Bars
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

            $draw->setFillColor($theme['bar']);
            $draw->setStrokeColor($theme['border']);
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle($bX1, $bY1, $bX2, $bY2, 5, 5);

            $draw->setStrokeColor('none');
            $draw->setFillColor('#f8fafc');
            $draw->setFontSize($barCount > 20 ? 11 : 14);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);

            $balLabel = $barCount > 18
                ? (string) round($bVal)
                : (string) ($b['balance_formatted'] ?? round($bVal));

            $draw->annotation($centerX, $bY1 - 20, $balLabel);

            $draw->setFillColor($theme['text']);
            $draw->setFontSize($barCount > 20 ? 11 : 13);
            $draw->setFontWeight(800);
            $draw->annotation($centerX, $bY1 - 6, (string) ($b['profit_formatted'] ?? ''));

            $draw->setFillColor('#94a3b8');
            $draw->setFontSize($barCount > 20 ? 11 : 14);
            $draw->setFontWeight(600);
            $draw->annotation($centerX, $plotBottom + 24, (string) ($b['settled_time'] ?? ''));

            $draw->setFillColor('#64748b');
            $draw->setFontSize($barCount > 20 ? 10 : 13);
            $draw->setFontWeight(500);
            $draw->annotation($centerX, $plotBottom + 46, (string) ($b['settled_date'] ?? ''));

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

    private function balanceChartCanvasHeight(bool $isEmpty): int
    {
        return $isEmpty ? 620 : 1080;
    }
}
