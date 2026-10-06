<?php

namespace App\Services;

use App\Services\LineSchedule\Renderers\BalanceChartRenderer;
use App\Services\LineSchedule\Renderers\BetHistoryRenderer;
use App\Services\LineSchedule\Renderers\BetsRenderer;
use App\Services\LineSchedule\Renderers\ScheduleRenderer;
use App\Services\LineSchedule\Support\ImageTheme;
use App\Services\LineSchedule\Support\TeamAbbreviation;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Imagick;
use RuntimeException;

class LineScheduleImageService
{
    private const CANVAS_WIDTH = 1440;

    private const CACHE_VERSION = 38;

    /** @var array<int, int> */
    private const IMAGE_WIDTHS = [700, 1440];

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
            return (new BalanceChartRenderer)->render($data);
        }

        if (($data['type'] ?? '') === 'bet_history') {
            return (new BetHistoryRenderer)->render($data);
        }

        if (($data['type'] ?? '') === 'bets' || isset($data['bets'])) {
            return (new BetsRenderer)->render($data);
        }

        return (new ScheduleRenderer)->render($data);
    }

    public function teamAbbreviation(string $name): string
    {
        return TeamAbbreviation::get($name);
    }

    /**
     * @return array{bg: string, border: string, text: string, label: string}
     */
    public function formatTheme(mixed $format): array
    {
        return ImageTheme::formatTheme($format);
    }

    public function seriesWinSlots(mixed $format): int
    {
        return ImageTheme::seriesWinSlots($format);
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
