<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use RuntimeException;

class LineScheduleImageService
{
    private const CANVAS_WIDTH = 1040;

    private const CACHE_VERSION = 3;

    private const CARD_HEIGHT = 160;

    private const CARD_GAP = 16;

    private const CARDS_TOP = 132;

    private const CANVAS_BOTTOM_PADDING = 30;

    /** @var array<int, int> */
    private const IMAGE_WIDTHS = [700, 1040];

    /**
     * Generate the image resolutions used by LINE and return their base URL.
     *
     * @param  array{
     *     title: string,
     *     subtitle: string,
     *     matches: array<int, array{
     *         start_time: string,
     *         format: string,
     *         team1: string,
     *         team2: string,
     *         tournament: string,
     *         odds: ?array{
     *             team1: array{price: float, bookmaker: string},
     *             team2: array{price: float, bookmaker: string}
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

        if (! $disk->exists($directory.'/1040')) {
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
     * @param  array{title: string, subtitle: string, matches: array<int, array<string, mixed>>}  $data
     */
    private function render(array $data): Imagick
    {
        $matches = array_slice($data['matches'], 0, 10);
        $canvasHeight = $this->canvasHeight(count($matches));
        $image = new Imagick;
        $image->newImage(self::CANVAS_WIDTH, $canvasHeight, '#0b1220', 'png');
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);

        $font = $this->fontPath();
        $draw = new ImagickDraw;
        $draw->setFont($font);
        $draw->setTextAntialias(true);

        $draw->setFillColor('#f8fafc');
        $draw->setFontSize(43);
        $draw->setFontWeight(700);
        $draw->annotation(46, 65, $this->fitText($image, $draw, $data['title'], 760, 34));

        $draw->setFillColor('#94a3b8');
        $draw->setFontSize(23);
        $draw->setFontWeight(400);
        $draw->annotation(48, 105, $this->fitText($image, $draw, $data['subtitle'], 760, 18));

        $count = count($matches);
        $draw->setFillColor('#2563eb');
        $draw->roundRectangle(852, 35, 994, 92, 28, 28);
        $draw->setFillColor('#ffffff');
        $draw->setFontSize(23);
        $draw->setFontWeight(700);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation(923, 72, $count.' 場');
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

        $cardWidth = 956;
        $left = 42;

        foreach ($matches as $index => $match) {
            $x = $left;
            $y = self::CARDS_TOP + ($index * (self::CARD_HEIGHT + self::CARD_GAP));

            $draw->setFillColor('#162033');
            $draw->roundRectangle($x, $y, $x + $cardWidth, $y + self::CARD_HEIGHT, 18, 18);

            $draw->setFillColor('#60a5fa');
            $draw->setFontSize(22);
            $draw->setFontWeight(700);
            $draw->annotation($x + 22, $y + 34, sprintf(
                '%s  ·  %s',
                $match['start_time'],
                $match['format'],
            ));

            $teams = sprintf('%s  vs  %s', $match['team1'], $match['team2']);
            $draw->setFillColor('#f8fafc');
            $draw->setFontSize(23);
            $draw->setFontWeight(700);
            $draw->annotation($x + 22, $y + 73, $this->fitText($image, $draw, $teams, $cardWidth - 44, 19));

            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(18);
            $draw->setFontWeight(400);
            $draw->annotation($x + 22, $y + 109, $this->fitText($image, $draw, (string) $match['tournament'], $cardWidth - 44, 16));

            $draw->setFillColor('#cbd5e1');
            $draw->setFontSize(18);
            $draw->annotation(
                $x + 22,
                $y + 141,
                $this->fitText($image, $draw, $this->oddsLabel($match), $cardWidth - 44, 16),
            );
        }

        $image->drawImage($draw);
        $image->stripImage();

        return $image;
    }

    private function canvasHeight(int $matchCount): int
    {
        return self::CARDS_TOP
            + ($matchCount * self::CARD_HEIGHT)
            + (max(0, $matchCount - 1) * self::CARD_GAP)
            + self::CANVAS_BOTTOM_PADDING;
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

    /** @param  array<string, mixed>  $match */
    private function oddsLabel(array $match): string
    {
        if ($match['odds'] === null) {
            return '獨贏：暫無盤口';
        }

        $bookmaker = (string) $match['odds']['team1']['bookmaker'];

        return sprintf(
            '獨贏：%.2f  /  %.2f · %s',
            $match['odds']['team1']['price'],
            $match['odds']['team2']['price'],
            $bookmaker,
        );
    }

    private function fontPath(): string
    {
        $configured = (string) config('services.line.schedule_image_font');
        $candidates = array_filter([
            $configured,
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKtc-Regular.otf',
        ]);

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('A Traditional Chinese font is required for LINE schedule images.');
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
