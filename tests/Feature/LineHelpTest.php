<?php

namespace Tests\Feature;

use App\Services\LineCommandRegistry;
use App\Services\LineHelpService;
use App\Services\LineSchedule\Renderers\HelpRenderer;
use App\Services\LineScheduleBot;
use App\Services\LineScheduleImageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Tests\TestCase;

class LineHelpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        config(['services.stake.access_token' => null]);
    }

    public function test_help_aliases_and_unicode_forms_return_the_same_image_without_external_data(): void
    {
        $bot = app(LineScheduleBot::class);
        $expected = $bot->reply('!h');
        $this->assertTrue($expected->prefersImage());
        $this->assertNull($expected->linkUrl);
        $this->assertSame('help', $expected->imageData['type']);
        foreach (['!help', '!H', '!HELP', '！ｈ', "\u{200B}!help\u{3000}"] as $command) {
            $this->assertEquals($expected, $bot->reply($command));
        }
        foreach (LineCommandRegistry::COMMANDS as $definition) {
            foreach ($definition['aliases'] as $alias) {
                $this->assertStringContainsString('!'.$alias, $expected->text);
            }
        }
        Http::assertNothingSent();
    }

    public function test_every_command_alias_opens_its_shared_help_page_without_credentials(): void
    {
        $bot = app(LineScheduleBot::class);
        foreach (LineCommandRegistry::COMMANDS as $group => $definition) {
            if ($group === 'help') {
                continue;
            }
            $expected = $bot->reply('!h '.$definition['page']);
            foreach ($definition['aliases'] as $alias) {
                $this->assertEquals($expected, $bot->reply('!'.$alias.' help'), $alias);
                $this->assertEquals($bot->reply('!h '.$definition['page'].' text'), $bot->reply('!'.$alias.' help text'), $alias);
            }
        }
        Http::assertNothingSent();
    }

    public function test_every_help_page_is_reachable_by_number_topic_and_buttons_with_a_text_alternative(): void
    {
        $bot = app(LineScheduleBot::class);
        $pages = app(LineHelpService::class)->pages();
        foreach (array_keys($pages) as $index => $key) {
            $reply = $bot->reply('!h '.($index + 1));
            $this->assertEquals($reply, $bot->reply('!help '.$key));
            $this->assertLessThanOrEqual(13, count($reply->quickReplies));
            foreach ($reply->quickReplies as $quick) {
                $this->assertLessThanOrEqual(20, mb_strlen($quick['label']));
                $this->assertNotNull($bot->reply($quick['text']));
            }
            $text = $bot->reply('!h '.$key.' text');
            $this->assertFalse($text->prefersImage());
            $this->assertSame($reply->text, $text->text);
            foreach ($text->quickReplies as $quick) {
                if ($quick['label'] !== '圖片版') {
                    $this->assertFalse($bot->reply($quick['text'])->prefersImage());
                }
            }
        }
        foreach (['!h 0', '!help 999', '!h unknown', '!h 2 3'] as $command) {
            $this->assertStringContainsString('找不到這個說明頁', $bot->reply($command)->text);
        }
        Http::assertNothingSent();
    }

    public function test_manual_contains_advanced_usage_and_tracks_configured_teams(): void
    {
        config(['nba.team_ids' => [9], 'mlb.team_ids' => []]);
        $help = app(LineHelpService::class);
        $this->assertStringContainsString('NBA 目前追蹤：金州勇士', $help->text('filters'));
        $this->assertStringContainsString('MLB 目前追蹤：未啟用追蹤', $help->text('filters'));
        foreach (['!odds G2 game=lol 1107', '!odds T1 1107', '!odds T1', '雙重賽'] as $example) {
            $this->assertStringContainsString($example, $help->text('odds'));
        }
        foreach (['page=2', '12 個盤口', '整場獨贏', '保留 text 文字模式'] as $feature) {
            $this->assertStringContainsString($feature, $help->text('markets'));
        }
        foreach (['odds', 'markets'] as $page) {
            $this->assertStringNotContainsString('all', $help->text($page));
            $this->assertStringNotContainsString('全部玩法', $help->text($page));
        }
        $this->assertStringContainsString('五項目皆會比對 Stake', $help->text('schedule'));
        $this->assertStringContainsString('最多 7 天', $help->text('schedule'));
        $this->assertStringContainsString('1～19', $help->text('filters'));
        $this->assertStringContainsString('最多 30 天', $help->text('records'));
        $this->assertStringContainsString('!bet balance', $help->text('bets'));
    }

    public function test_all_help_images_wrap_the_complete_text_and_fit_the_canvas(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick required.');
        }
        $renderer = new HelpRenderer;
        foreach (app(LineHelpService::class)->pages() as $key => $page) {
            $data = app(LineScheduleBot::class)->reply('!h '.$key)->imageData;
            $layout = $renderer->layout($data);
            $image = $renderer->render($data);
            $this->assertSame(1440, $image->getImageWidth());
            $this->assertSame($layout['height'], $image->getImageHeight());
            $this->assertLessThanOrEqual(5000, $image->getImageHeight(), $key);
            foreach ($layout['sections'] as $index => $section) {
                $expected = implode('', $page['sections'][$index]['lines']);
                $actual = implode('', array_column($section['lines'], 'text'));
                $this->assertSame(preg_replace('/\s+/u', '', $expected), preg_replace('/\s+/u', '', $actual), $key);
                $this->assertLessThan($layout['height'] - 72, $section['y'] + $section['height']);
            }
            $this->assertLessThan(5 * 1024 * 1024, strlen($image->getImageBlob()), $key);
            $image->clear();
        }
    }

    public function test_help_images_use_both_line_resolutions_and_cache_without_a_link(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick required.');
        }
        config(['services.line.schedule_image_disk' => 'help-images']);
        Storage::fake('help-images');
        $data = app(LineScheduleBot::class)->reply('!h')->imageData;
        $service = app(LineScheduleImageService::class);
        $url = $service->create($data);
        $this->assertSame($url, $service->create($data));
        $files = Storage::disk('help-images')->allFiles('line-schedules');
        $this->assertCount(2, $files);
        foreach ($files as $path) {
            $image = new Imagick;
            $image->readImageBlob(Storage::disk('help-images')->get($path));
            $this->assertSame((int) basename($path), $image->getImageWidth());
            $this->assertSame('PNG', $image->getImageFormat());
            $image->clear();
        }
        $data['sections'][0]['lines'][] = '更新後的使用方式';
        $this->assertNotSame($url, $service->create($data));
    }
}
