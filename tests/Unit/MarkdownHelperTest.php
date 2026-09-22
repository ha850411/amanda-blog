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

    public function test_tables_preserve_headers_and_price_relationships(): void
    {
        $markdown = MarkdownHelper::htmlToMarkdown('<table><tr><th>品項</th><th>價格</th></tr><tr><td>拿鐵</td><td>150元</td></tr><tr><td>茶|咖啡</td><td>100元<br>限平日</td></tr></table>');
        $this->assertStringContainsString("| 品項 | 價格 |\n| --- | --- |\n| 拿鐵 | 150元 |", $markdown);
        $this->assertStringContainsString('| 茶\\|咖啡 | 100元<br>限平日 |', $markdown);
    }

    public function test_ordered_and_nested_lists_preserve_their_hierarchy(): void
    {
        $markdown = MarkdownHelper::htmlToMarkdown('<ol start="3"><li>交通<ul><li>捷運</li><li>步行</li></ul></li><li>營業時間</li></ol>');
        $this->assertSame("3. 交通\n    - 捷運\n    - 步行\n4. 營業時間", $markdown);
    }

    public function test_merged_table_cells_are_preserved_as_html_in_markdown(): void
    {
        $markdown = MarkdownHelper::htmlToMarkdown('<table><tr><th colspan="2">套餐</th></tr><tr><td>咖啡</td><td>甜點</td></tr></table>');
        $this->assertStringContainsString('colspan="2"', $markdown);
        $this->assertStringContainsString('<td>咖啡</td><td>甜點</td>', $markdown);
    }

    public function test_images_entities_and_code_remain_readable_without_scripts(): void
    {
        $markdown = MarkdownHelper::htmlToMarkdown('<p>A &amp; B</p><img alt="拿鐵" src="/latte.jpg"><pre>a  b\nc</pre><script>unwanted()</script>');
        $this->assertStringContainsString('A & B', $markdown);
        $this->assertStringContainsString('![拿鐵](/latte.jpg)', $markdown);
        $this->assertStringContainsString('a  b', $markdown);
        $this->assertStringNotContainsString('unwanted', $markdown);
    }
}
