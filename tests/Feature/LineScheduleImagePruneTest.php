<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LineScheduleImagePruneTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_only_deletes_s3_schedule_images_older_than_seven_days(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 12:00:00');
        config([
            'services.line.schedule_image_disk' => 's3',
            'services.line.schedule_image_retention_days' => 7,
        ]);
        Storage::fake('s3');

        $disk = Storage::disk('s3');
        $expired = 'line-schedules/expired/1040';
        $recent = 'line-schedules/recent/1040';
        $unrelated = 'images/old-article.png';

        $disk->put($expired, 'expired');
        $disk->put($recent, 'recent');
        $disk->put($unrelated, 'unrelated');
        touch($disk->path($expired), now()->subDays(8)->getTimestamp());
        touch($disk->path($recent), now()->subDays(6)->getTimestamp());
        touch($disk->path($unrelated), now()->subDays(30)->getTimestamp());

        $this->assertSame(0, Artisan::call('line:schedule-images-prune'));

        $disk->assertMissing($expired);
        $disk->assertExists($recent);
        $disk->assertExists($unrelated);
    }
}
