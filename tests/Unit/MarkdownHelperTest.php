<?php

namespace Tests\Unit;

use App\Support\MarkdownHelper;
use PHPUnit\Framework\TestCase;

class MarkdownHelperTest extends TestCase
{
    public function test_html_to_markdown_conversion(): void
    {
        $html = '<h2>美食體驗</h2><p>這是<strong>精選</strong>牛肉麵<br>歡迎品嚐</p><a href="https://example.com">店家網站</a>';
        $markdown = MarkdownHelper::htmlToMarkdown($html);

        $this->assertStringContainsString('## 美食體驗', $markdown);
        $this->assertStringContainsString('**精選**', $markdown);
        $this->assertStringContainsString('[店家網站](https://example.com)', $markdown);
    }
}
