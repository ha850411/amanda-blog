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

    private const CACHE_VERSION = 5;

    private const CARD_HEIGHT = 180;

    private const CARD_GAP = 18;

    private const CARDS_TOP = 140;

    private const CANVAS_BOTTOM_PADDING = 34;

    /** @var array<int, int> */
    private const IMAGE_WIDTHS = [700, 1040];

    private const GAME_THEMES = [
        'cs' => [
            'accent' => '#f59e0b',
            'badge_bg' => '#2e1b06',
            'badge_text' => '#fde68a',
            'label' => 'CS2',
        ],
        'valorant' => [
            'accent' => '#ff4655',
            'badge_bg' => '#300a14',
            'badge_text' => '#fecdd3',
            'label' => 'VAL',
        ],
        'lol' => [
            'accent' => '#0ea5e9',
            'badge_bg' => '#052438',
            'badge_text' => '#bae6fd',
            'label' => 'LoL',
        ],
        'default' => [
            'accent' => '#3b82f6',
            'badge_bg' => '#172554',
            'badge_text' => '#bfdbfe',
            'label' => 'MATCH',
        ],
    ];

    /**
     * Generate the image resolutions used by LINE and return their base URL.
     *
     * @param  array{
     *     title: string,
     *     subtitle: string,
     *     game?: ?string,
     *     matches: array<int, array{
     *         start_time: string,
     *         format: string,
     *         team1: string,
     *         team2: string,
     *         tournament: string,
     *         game?: ?string,
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
     * @param  array{title: string, subtitle: string, game?: ?string, matches: array<int, array<string, mixed>>}  $data
     */
    private function render(array $data): Imagick
    {
        $matches = array_slice($data['matches'], 0, 10);
        $canvasHeight = $this->canvasHeight(count($matches));
        $image = new Imagick;
        $image->newImage(self::CANVAS_WIDTH, $canvasHeight, '#090d16', 'png');
        $image->setImageColorspace(Imagick::COLORSPACE_SRGB);

        $font = $this->fontPath();
        $draw = new ImagickDraw;
        $draw->setFont($font);
        $draw->setTextAntialias(true);

        // Header Title
        $draw->setFillColor('#f8fafc');
        $draw->setFontSize(40);
        $draw->setFontWeight(700);
        $draw->annotation(46, 64, $this->fitText($image, $draw, $data['title'], 760, 32));

        // Header Subtitle
        $draw->setFillColor('#94a3b8');
        $draw->setFontSize(21);
        $draw->setFontWeight(400);
        $draw->annotation(48, 104, $this->fitText($image, $draw, $data['subtitle'], 760, 17));

        // Top Right Count Badge
        $count = count($matches);
        $headerTheme = $this->themeForGame($data['game'] ?? null);
        $draw->setFillColor($headerTheme['accent']);
        $draw->roundRectangle(852, 36, 994, 92, 28, 28);
        $draw->setFillColor('#ffffff');
        $draw->setFontSize(22);
        $draw->setFontWeight(700);
        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation(923, 72, $count.' 場');
        $draw->setTextAlignment(Imagick::ALIGN_LEFT);

        $cardWidth = 952;
        $left = 44;
        $iconsToDraw = [];

        foreach ($matches as $index => $match) {
            $x = $left;
            $y = self::CARDS_TOP + ($index * (self::CARD_HEIGHT + self::CARD_GAP));
            $matchGame = $match['game'] ?? $data['game'] ?? null;
            $theme = $this->themeForGame($matchGame);

            // Card Container Background & Border
            $draw->setFillColor('#131b2e');
            $draw->setStrokeColor('#1f2d47');
            $draw->setStrokeWidth(1.5);
            $draw->roundRectangle($x, $y, $x + $cardWidth, $y + self::CARD_HEIGHT, 16, 16);

            // Left Theme Indicator Bar
            $draw->setFillColor($theme['accent']);
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->roundRectangle($x + 2, $y + 14, $x + 6, $y + self::CARD_HEIGHT - 14, 2, 2);

            // Card Top Row: Game Icon / Badge / Time / Format / Tournament
            $iconPath = $this->iconPathForGame($matchGame);
            $timeX = $x + 24;

            if ($iconPath !== null) {
                $iconSize = 28;
                $iconsToDraw[] = [
                    'path' => $iconPath,
                    'x' => $x + 22,
                    'y' => $y + 13,
                    'size' => $iconSize,
                ];
                $timeX = $x + 22 + $iconSize + 12;
            } elseif ($matchGame !== null) {
                $badgeWidth = 52;
                $draw->setFillColor($theme['badge_bg']);
                $draw->setStrokeColor($theme['accent']);
                $draw->setStrokeWidth(1);
                $draw->roundRectangle($x + 22, $y + 15, $x + 22 + $badgeWidth, $y + 39, 6, 6);

                $draw->setFillColor($theme['badge_text']);
                $draw->setStrokeColor('none');
                $draw->setStrokeWidth(0);
                $draw->setFontSize(13);
                $draw->setFontWeight(700);
                $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                $draw->annotation($x + 22 + (int) round($badgeWidth / 2), $y + 32, $theme['label']);
                $draw->setTextAlignment(Imagick::ALIGN_LEFT);

                $timeX = $x + 22 + $badgeWidth + 12;
            }

            // Start Time
            $draw->setFillColor('#f8fafc');
            $draw->setFontSize(21);
            $draw->setFontWeight(700);
            $draw->annotation($timeX, $y + 34, $match['start_time']);

            $timeMetrics = $image->queryFontMetrics($draw, $match['start_time']);
            $formatX = $timeX + (int) round($timeMetrics['textWidth']) + 8;

            // Format (e.g. · BO3)
            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(17);
            $draw->setFontWeight(400);
            $draw->annotation($formatX, $y + 34, '· '.$match['format']);

            // Tournament Name (Top Right)
            $draw->setFillColor('#94a3b8');
            $draw->setFontSize(16);
            $draw->setFontWeight(400);
            $draw->setTextAlignment(Imagick::ALIGN_RIGHT);
            $tournamentText = $this->fitText($image, $draw, (string) $match['tournament'], 440, 13);
            $draw->annotation($x + $cardWidth - 22, $y + 34, $tournamentText);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Card Middle Row: Symmetrical Team 1 & Team 2 Boxes
            $boxY = $y + 50;
            $boxHeight = 74;
            $boxWidth = 418;

            // Team 1 Box (Left)
            $draw->setFillColor('#1a243b');
            $draw->setStrokeColor('#293852');
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle($x + 22, $boxY, $x + 22 + $boxWidth, $boxY + $boxHeight, 10, 10);

            // Team 1 Name
            $draw->setFillColor('#f8fafc');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(20);
            $draw->setFontWeight(700);
            $draw->annotation($x + 38, $boxY + 45, $this->fitText($image, $draw, $match['team1'], 270, 14));

            // Team 1 Odds Pill
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x + 332, $boxY + 14, $x + 430, $boxY + 60, 8, 8);

            $hasOdds = ($match['odds'] ?? null) !== null;
            $team1Odds = $hasOdds ? sprintf('%.2f', $match['odds']['team1']['price']) : '—';
            $draw->setFillColor($hasOdds ? '#38bdf8' : '#64748b');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(18);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + 381, $boxY + 44, $team1Odds);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Center VS Badge
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#293852');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($x + 456, $boxY + 17, $x + 496, $boxY + 57, 20, 20);

            $draw->setFillColor('#94a3b8');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(14);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($x + 476, $boxY + 42, 'VS');
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Team 2 Box (Right)
            $team2BoxX = $x + 512;
            $draw->setFillColor('#1a243b');
            $draw->setStrokeColor('#293852');
            $draw->setStrokeWidth(1.2);
            $draw->roundRectangle($team2BoxX, $boxY, $team2BoxX + $boxWidth, $boxY + $boxHeight, 10, 10);

            // Team 2 Name
            $draw->setFillColor('#f8fafc');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(20);
            $draw->setFontWeight(700);
            $draw->annotation($team2BoxX + 16, $boxY + 45, $this->fitText($image, $draw, $match['team2'], 270, 14));

            // Team 2 Odds Pill
            $draw->setFillColor('#0d1524');
            $draw->setStrokeColor('#1e293b');
            $draw->setStrokeWidth(1);
            $draw->roundRectangle($team2BoxX + 310, $boxY + 14, $team2BoxX + 408, $boxY + 60, 8, 8);

            $team2Odds = $hasOdds ? sprintf('%.2f', $match['odds']['team2']['price']) : '—';
            $draw->setFillColor($hasOdds ? '#38bdf8' : '#64748b');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(18);
            $draw->setFontWeight(700);
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->annotation($team2BoxX + 359, $boxY + 44, $team2Odds);
            $draw->setTextAlignment(Imagick::ALIGN_LEFT);

            // Card Bottom Row: Odds / Bookmaker Source
            $statusText = $hasOdds
                ? ('獨贏盤口 · 來源：'.(string) $match['odds']['team1']['bookmaker'])
                : '獨贏盤口 · 暫無盤口';
            $draw->setFillColor('#64748b');
            $draw->setStrokeColor('none');
            $draw->setStrokeWidth(0);
            $draw->setFontSize(14);
            $draw->setFontWeight(400);
            $draw->annotation($x + 24, $y + 154, $statusText);
        }

        $image->drawImage($draw);

        foreach ($iconsToDraw as $iconData) {
            try {
                $icon = new Imagick($iconData['path']);
                $icon->resizeImage($iconData['size'], $iconData['size'], Imagick::FILTER_LANCZOS, 1);
                $image->compositeImage($icon, Imagick::COMPOSITE_OVER, $iconData['x'], $iconData['y']);
                $icon->clear();
            } catch (\Throwable) {
                // If icon cannot be loaded, gracefully continue.
            }
        }

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

    private function iconPathForGame(?string $game): ?string
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
        ];

        $key = $aliases[$normalized] ?? null;

        if ($key === null) {
            return null;
        }

        $candidates = [
            resource_path("images/games/{$key}.png"),
            base_path("resources/images/games/{$key}.png"),
            __DIR__."/../../resources/images/games/{$key}.png",
        ];

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{accent: string, badge_bg: string, badge_text: string, label: string}
     */
    private function themeForGame(?string $game): array
    {
        $normalized = mb_strtolower(trim((string) $game));

        return self::GAME_THEMES[$normalized] ?? self::GAME_THEMES['default'];
    }

    private function fontPath(): string
    {
        $configured = (string) config('services.line.schedule_image_font');
        $candidates = array_filter([
            $configured,
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKtc-Regular.otf',
            '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
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
