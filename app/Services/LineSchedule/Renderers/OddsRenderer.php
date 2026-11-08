<?php

namespace App\Services\LineSchedule\Renderers;

use Imagick;
use ImagickDraw;

class OddsRenderer extends AbstractImageRenderer
{
    public function render(array $data): Imagick
    {
        $markets = $data['markets'] ?? [];
        $liveScore = $data['live_score'] ?? null;
        $scoreHeight = $liveScore !== null ? 58 : 0;
        $height = 330 + $scoreHeight;
        $group = null;
        foreach ($markets as $market) {
            if ($group !== $market['group_label']) {
                $height += 56;
                $group = $market['group_label'];
            }
            $height += 60 + 54 * (int) ceil(count($market['outcomes']) / 2);
        }
        $height += $markets === [] ? 120 : count($data['missing_groups'] ?? []) * 38;
        $image = new Imagick;
        $image->newImage(self::CANVAS_WIDTH, $height, '#0b1220');
        $image->setImageFormat('png');
        $draw = new ImagickDraw;
        $draw->setFont($this->fontPath());

        $this->scheduleRawText($draw, 48, 62, $data['title'], 28, '#38bdf8', 700);
        if (! empty($data['fixture_code'])) {
            $this->scheduleRawText($draw, 1392, 62, '遊戲編號 '.$data['fixture_code'], 26, '#38bdf8', 700, Imagick::ALIGN_RIGHT);
        }
        $this->scheduleText($image, $draw, 48, 120, $data['fixture_name'], 40, '#f8fafc', 700, 1336);
        $this->scheduleText($image, $draw, 48, 162, $data['tournament'], 23, '#94a3b8');
        $this->scheduleRawText($draw, 48, 205, '開賽 '.$data['start_time'].' 台灣時間', 24, '#e2e8f0');
        $this->scheduleRawText($draw, 1392, 205, $data['status'], 24, '#38bdf8', 600, Imagick::ALIGN_RIGHT);
        if ($liveScore !== null) {
            $this->scheduleRawText($draw, 48, 258, '目前比分｜'.$liveScore, 30, '#4ade80', 700);
        }

        $y = 230 + $scoreHeight;
        $group = null;
        foreach ($markets as $market) {
            if ($group !== $market['group_label']) {
                $group = $market['group_label'];
                $this->scheduleRawText($draw, 48, $y + 37, $group, 27, '#38bdf8', 700);
                $y += 56;
            }
            $rows = (int) ceil(count($market['outcomes']) / 2);
            $cardHeight = 48 + $rows * 54;
            $this->scheduleBox($draw, 44, $y, 1352, $cardHeight, '#152238', '#25354e', 12);
            $this->scheduleText($image, $draw, 66, $y + 32, $market['name'], 22, '#cbd5e1', 500, 1300);
            foreach ($market['outcomes'] as $i => $outcome) {
                $x = 66 + ($i % 2) * 672;
                $rowY = $y + 77 + intdiv($i, 2) * 54;
                $color = $outcome['available'] ? '#f8fafc' : '#64748b';
                $this->scheduleText($image, $draw, $x, $rowY, $outcome['name'], 24, $color, 500, 490);
                $this->scheduleRawText($draw, $x + 620, $rowY, $outcome['price'], 28,
                    $outcome['available'] ? '#4ade80' : '#94a3b8', 700, Imagick::ALIGN_RIGHT);
            }
            $y += $cardHeight + 12;
        }
        if ($markets === []) {
            $this->scheduleRawText($draw, 48, $y + 65, '獨贏／讓分／大小分：暫無盤口', 28, '#94a3b8');
        } else {
            foreach ($data['missing_groups'] ?? [] as $label) {
                $this->scheduleRawText($draw, 48, $y + 29, $label.'：暫無盤口', 22, '#94a3b8');
                $y += 38;
            }
        }

        $this->scheduleRawText($draw, 48, $height - 62, '查詢 '.$data['checked_at'].'（台灣時間）', 20, '#94a3b8');
        $this->scheduleRawText($draw, 1392, $height - 62, '第 '.$data['page'].' / '.$data['pages'].' 頁', 20, '#94a3b8', 400, Imagick::ALIGN_RIGHT);
        $this->scheduleRawText($draw, 48, $height - 28, '僅整場盤口・小數賠率・以 Stake 當下顯示為準', 19, '#64748b');
        $image->drawImage($draw);
        $draw->clear();

        return $image;
    }
}
