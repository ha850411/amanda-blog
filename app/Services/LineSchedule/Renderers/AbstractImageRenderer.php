<?php

namespace App\Services\LineSchedule\Renderers;

use Imagick;
use ImagickDraw;
use RuntimeException;

abstract class AbstractImageRenderer
{
    protected const CANVAS_WIDTH = 1440;

    abstract public function render(array $data): Imagick;

    protected function scheduleRawText(
        ImagickDraw $draw,
        int $x,
        int $y,
        string $text,
        int $size,
        string $color,
        int $weight = 400,
        int $align = Imagick::ALIGN_LEFT
    ): void {
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFillColor($color);
        $draw->setFontSize($size);
        $draw->setFontWeight($weight);
        $draw->setTextAlignment($align);
        $draw->annotation($x, $y, $text);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);
    }

    protected function scheduleText(
        Imagick $image,
        ImagickDraw $draw,
        int $x,
        int $y,
        string $text,
        int $size,
        string $color,
        int $weight = 400,
        int $width = 1352
    ): void {
        $draw->setStrokeColor('none');
        $draw->setStrokeWidth(0);
        $draw->setFillColor($color);
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);
        $draw->setFontSize($size);
        $draw->setFontWeight($weight);
        $draw->annotation($x, $y, $this->fitText($image, $draw, $text, $width, max(12, $size - 4)));
    }

    protected function scheduleBox(
        ImagickDraw $draw,
        int $x,
        int $y,
        int $width,
        int $height,
        string $fill,
        string $border,
        int $radius,
        float $borderWidth = 1.0
    ): void {
        $draw->setFillColor($fill);
        $draw->setStrokeColor($border);
        $draw->setStrokeWidth($borderWidth);
        $draw->roundRectangle($x, $y, $x + $width, $y + $height, $radius, $radius);
    }

    protected function fitText(
        Imagick $image,
        ImagickDraw $draw,
        string $text,
        int $maxWidth,
        int $minimumFontSize
    ): string {
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

    protected function iconPathForGame(?string $game): ?string
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
            'mlb' => 'mlb',
            'baseball' => 'mlb',
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
            __DIR__."/../../../../public/images/games/{$key}.png",
            __DIR__."/../../../../resources/images/games/{$key}.png",
        ];

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function fontPath(): string
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
}
