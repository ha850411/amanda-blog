<?php

namespace App\Services\LineSchedule\Renderers;

use Imagick;
use ImagickDraw;

class HelpRenderer extends AbstractImageRenderer
{
    public function render(array $data): Imagick
    {
        $layout = $this->layout($data);
        $image = new Imagick;
        $image->newImage(self::CANVAS_WIDTH, $layout['height'], '#0b1220');
        $image->setImageFormat('png');
        $draw = new ImagickDraw;
        $draw->setFont($this->fontPath());
        $this->scheduleRawText($draw, 54, 62, $data['title'], 28, '#38bdf8', 700);
        $this->scheduleRawText($draw, 54, 126, $data['topic'], 44, '#f8fafc', 700);
        $this->scheduleRawText($draw, 1386, 62, $data['page'].' / '.$data['pages'], 27, '#94a3b8', 400, Imagick::ALIGN_RIGHT);
        $this->scheduleRawText($draw, 54, 174, '!h・!help　點下方分類切換｜點開圖片可放大', 25, '#94a3b8');
        foreach ($layout['sections'] as $section) {
            $this->scheduleBox($draw, 44, $section['y'], 1352, $section['height'], '#152238', '#25354e', 14);
            $this->scheduleRawText($draw, 70, $section['y'] + 43, $section['title'], 29, '#38bdf8', 700);
            foreach ($section['lines'] as $line) {
                $this->scheduleRawText($draw, 70, $line['y'], $line['text'], 30, $line['command'] ? '#f8fafc' : '#cbd5e1');
            }
        }
        $this->scheduleRawText($draw, 54, $layout['height'] - 34, '!h '.$data['page'].' text 查看文字版｜!h 回到總覽', 24, '#94a3b8');
        $image->drawImage($draw);
        $draw->clear();

        return $image;
    }

    /** Measure every line before rendering; help text must wrap instead of being ellipsized. */
    public function layout(array $data): array
    {
        $metric = new Imagick;
        $metric->newImage(1, 1, 'transparent');
        $draw = new ImagickDraw;
        $draw->setFont($this->fontPath());
        $draw->setFontSize(30);
        $sections = [];
        $y = 204;
        foreach ($data['sections'] as $section) {
            $lines = [];
            $baseline = $y + 91;
            foreach ($section['lines'] as $paragraph) {
                foreach ($this->wrap($metric, $draw, $paragraph, 1296) as $text) {
                    $lines[] = ['text' => $text, 'y' => $baseline, 'command' => str_starts_with($paragraph, '!')];
                    $baseline += 45;
                }
                $baseline += 9;
            }
            $height = $baseline - $y - 20;
            $sections[] = ['title' => $section['title'], 'y' => $y, 'height' => $height, 'lines' => $lines];
            $y += $height + 18;
        }
        $metric->clear();
        $draw->clear();

        return ['height' => $y + 72, 'sections' => $sections];
    }

    private function wrap(Imagick $image, ImagickDraw $draw, string $text, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (mb_str_split($text) as $character) {
            $candidate = $line.$character;
            if ($line !== '' && $image->queryFontMetrics($draw, $candidate)['textWidth'] > $width) {
                $lines[] = rtrim($line);
                $line = ltrim($character);
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = rtrim($line);
        }

        return $lines;
    }
}
