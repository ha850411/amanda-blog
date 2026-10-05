<?php

namespace App\Services\LineSchedule\Renderers;

use Imagick;
use ImagickDraw;

class BetHistoryRenderer extends AbstractImageRenderer
{
    private const CANVAS_BOTTOM_PADDING = 30;

    /**
     * @param  array{
     *     title?: string,
     *     subtitle?: string,
     *     summary?: array<string, mixed>,
     *     omitted_count?: int,
     *     balance_formatted?: string,
     *     bets: array<int, array<string, mixed>>
     * }  $data
     */
    public function render(array $data): Imagick
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
}
