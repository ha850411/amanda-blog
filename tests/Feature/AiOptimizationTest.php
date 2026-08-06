<?php

namespace Tests\Feature;

use Tests\TestCase;

class AiOptimizationTest extends TestCase
{
    public function test_robots_txt_file_contains_ai_bot_rules(): void
    {
        $robotsPath = public_path('robots.txt');
        $this->assertFileExists($robotsPath);
        $content = file_get_contents($robotsPath);

        $this->assertStringContainsString('GPTBot', $content);
        $this->assertStringContainsString('ClaudeBot', $content);
        $this->assertStringContainsString('PerplexityBot', $content);
        $this->assertStringContainsString('Link: /llms.txt', $content);
        $this->assertStringContainsString('Sitemap: /sitemap.xml', $content);
    }

    public function test_ai_routes_are_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('llms.txt'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('llms.full.txt'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('article.markdown'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('rss'));
    }
}
