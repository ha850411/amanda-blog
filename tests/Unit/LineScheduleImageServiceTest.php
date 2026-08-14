<?php

namespace Tests\Unit;

use App\Services\LineScheduleImageService;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Tests\TestCase;

class LineScheduleImageServiceTest extends TestCase
{
    public function test_it_renders_one_match_per_row_and_grows_the_image_height(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required to verify schedule image layout.');
        }

        $font = collect([
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ])->first(fn (string $path): bool => is_readable($path));

        if ($font === null) {
            $this->markTestSkipped('A readable font is required to verify schedule image layout.');
        }

        config([
            'services.line.schedule_image_disk' => 'schedule-images',
            'services.line.schedule_image_font' => $font,
        ]);
        Storage::fake('schedule-images');

        $matches = array_map(fn (int $number): array => [
            'start_time' => sprintf('%02d:00', 14 + $number),
            'format' => 'BO3',
            'team1' => 'Team '.$number.' Alpha',
            'team2' => 'Team '.$number.' Beta',
            'tournament' => 'VCT 2026: Test Stage',
            'odds' => null,
            'h2h' => [
                'sample_size' => 5,
                'history_total' => 12,
                'team1_wins' => 3,
                'team2_wins' => 2,
                'team1_games' => 7,
                'team2_games' => 5,
                'series' => [
                    ['date' => '08/01', 'format' => 'BO3', 'team1_score' => 2, 'team2_score' => 0, 'winner' => 'team1'],
                    ['date' => '07/15', 'format' => 'BO5', 'team1_score' => 2, 'team2_score' => 3, 'winner' => 'team2'],
                    ['date' => '06/20', 'format' => 'BO1', 'team1_score' => 1, 'team2_score' => 0, 'winner' => 'team1'],
                    ['date' => '05/09', 'format' => 'BO3', 'team1_score' => 1, 'team2_score' => 2, 'winner' => 'team2'],
                    ['date' => '04/18', 'format' => 'BO3', 'team1_score' => 2, 'team2_score' => 0, 'winner' => 'team1'],
                ],
            ],
        ], range(1, 3));

        app(LineScheduleImageService::class)->create([
            'title' => 'VALORANT｜08/12｜S Tier',
            'subtitle' => '台灣時間｜3 場賽程',
            'matches' => $matches,
        ], 'https://bo3.gg/valorant/matches/current');

        $files = Storage::disk('schedule-images')->allFiles('line-schedules');
        $originalPath = collect($files)->first(fn (string $path): bool => str_ends_with($path, '/1440'));
        $previewPath = collect($files)->first(fn (string $path): bool => str_ends_with($path, '/700'));

        $this->assertNotNull($originalPath);
        $this->assertNotNull($previewPath);

        $original = new Imagick;
        $original->readImageBlob(Storage::disk('schedule-images')->get($originalPath));
        $preview = new Imagick;
        $preview->readImageBlob(Storage::disk('schedule-images')->get($previewPath));

        $this->assertSame(1440, $original->getImageWidth());
        $this->assertSame(750, $original->getImageHeight());
        $this->assertSame(700, $preview->getImageWidth());
        $this->assertSame(365, $preview->getImageHeight());
        $this->assertSame(
            ['r' => 19, 'g' => 27, 'b' => 46, 'a' => 1],
            $original->getImagePixelColor(520, 145)->getColor(),
        );

        $original->clear();
        $preview->clear();
    }
}
