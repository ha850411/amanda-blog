<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Tag;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public function test_article_body_and_title_are_readable_without_javascript(): void
    {
        $article = Article::factory()->create(['status' => 1, 'title' => '可讀的文章', 'content' => '<p>唯一正文內容 {{ literal }}</p>']);
        $response = $this->get('/article/'.$article->id)->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(1, $xpath->query('//main//h1[not(ancestor::template)]')->length);
        $body = $xpath->query('//main//div[contains(@class,"article-content")][not(ancestor::template)]');
        $this->assertSame(1, $body->length);
        $this->assertSame('唯一正文內容 {{ literal }}', $body->item(0)->textContent);
        $this->assertSame('', $response->viewData('frontendArticle')['content']);
        $json = json_decode($xpath->query('//script[@type="application/ld+json"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('BlogPosting', $json['@type']);
        $this->assertArrayNotHasKey('articleBody', $json);
    }

    public function test_listing_pages_have_real_links_and_distinct_canonical_urls(): void
    {
        Article::factory()->count(11)->create(['status' => 1]);
        $first = $this->get('/?utm_source=test')->assertOk();
        $xpath = $this->xpath($first->getContent());
        $this->assertSame(5, $xpath->query('//main//article[not(ancestor::template)]')->length);
        $this->assertSame(url('/'), $xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        $this->assertSame(url('/').'?page=2', $xpath->query('//nav[@aria-label="文章分頁"]/a')->item(0)->getAttribute('href'));
        $ids = collect($first->viewData('initialArticles'))->pluck('id')->all();

        $second = $this->get('/?page=2&utm_source=test')->assertOk();
        $this->assertSame([], array_intersect($ids, collect($second->viewData('initialArticles'))->pluck('id')->all()));
        $second->assertSee('href="'.url('/').'?page=2"', false);
        $this->assertSame(5, $this->xpath($second->getContent())->query('//main//article')->length);
        $this->get('/?page=4')->assertNotFound();
        $this->get('/?page=-1')->assertNotFound();
        $this->get('/?page[]=1')->assertNotFound();
    }

    public function test_category_pagination_and_api_use_the_same_order_and_filter(): void
    {
        $tag = Tag::factory()->create();
        $articles = Article::factory()->count(7)->create(['status' => 1, 'updated_at' => '2026-01-01 00:00:00']);
        foreach ($articles as $article) {
            $article->tags()->attach($tag);
        }
        Article::factory()->create(['status' => 1]);
        $page = $this->get('/tag/'.$tag->id.'?page=2')->assertOk();
        $api = $this->getJson('/api/article?tagId='.$tag->id.'&page=2&perpage=5')->assertOk();
        $this->assertCount(2, $page->viewData('initialArticles'));
        $this->assertSame(collect($api->json('data'))->pluck('id')->all(), collect($page->viewData('initialArticles'))->pluck('id')->all());
        $page->assertSee('href="'.url('/tag/'.$tag->id).'?page=2"', false);
        $this->get('/tag/999999')->assertNotFound();
    }

    public function test_hidden_articles_are_unavailable_on_every_public_entry_point(): void
    {
        foreach ([0, 3] as $status) {
            $article = Article::factory()->create(['status' => $status, 'title' => 'HIDDEN_TITLE_'.$status, 'content' => 'HIDDEN_CONTENT_'.$status]);
            $this->get('/article/'.$article->id)->assertNotFound();
            $this->get('/article/'.$article->id.'.md')->assertNotFound();
            $this->postJson('/api/article/'.$article->id.'/verify')->assertNotFound();
        }
        foreach (['/', '/llms.txt', '/llms-full.txt', '/rss.xml', '/api/article', '/api/article?status=3', '/api/article?status[]=1&status[]=3'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('HIDDEN_TITLE')->assertDontSee('HIDDEN_CONTENT');
        }
    }

    public function test_admin_can_still_list_and_edit_hidden_articles(): void
    {
        \App\Models\About::factory()->create();
        $admin = Admin::create(['username' => 'seo-admin', 'password' => 'secret']);
        $article = Article::factory()->create(['status' => 3]);
        $this->actingAs($admin, 'admin')->getJson('/api/article?status=3')
            ->assertOk()->assertJsonPath('data.0.id', $article->id);
        $this->get('/admin/article/'.$article->id)->assertOk();
    }

    public function test_locked_content_and_images_never_appear_in_metadata_or_feeds(): void
    {
        $article = Article::factory()->create(['status' => 2, 'password' => 'secret', 'content' => '<p>PRIVATE_BODY</p><img src="https://example.com/PRIVATE_IMAGE.jpg">']);
        foreach (['/article/'.$article->id, '/article/'.$article->id.'.md', '/', '/api/article?show_first_image=1', '/llms.txt', '/llms-full.txt', '/rss.xml'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('PRIVATE_BODY')->assertDontSee('PRIVATE_IMAGE');
        }
        $response = $this->get('/article/'.$article->id);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//meta[@name="robots"]')->length);
        $this->assertSame('noindex, nofollow', $xpath->query('//meta[@name="robots"]')->item(0)->getAttribute('content'));
        $this->get('/article/'.$article->id.'.md')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_verified_content_is_private_and_password_changes_revoke_access(): void
    {
        $article = Article::factory()->create(['status' => 2, 'password' => 'secret', 'content' => '<p>VERIFIED_BODY</p>']);
        $verified = $this->postJson('/api/article/'.$article->id.'/verify', ['password' => 'secret'])->assertOk();
        $cookie = collect($verified->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'article_password_cache_id');
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        $response = $this->get('/article/'.$article->id)->assertOk()->assertSee('VERIFIED_BODY');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/article/'.$article->id.'.md')->assertOk()->assertSee('VERIFIED_BODY')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $article->update(['password' => 'new-secret']);
        $this->get('/article/'.$article->id)->assertOk()->assertDontSee('VERIFIED_BODY');
        $this->get('/article/'.$article->id.'.md')->assertOk()->assertDontSee('VERIFIED_BODY');
    }

    public function test_sitemap_and_markdown_reference_only_public_canonical_pages(): void
    {
        $public = Article::factory()->create(['status' => 1]);
        $locked = Article::factory()->create(['status' => 2]);
        $hidden = Article::factory()->create(['status' => 3]);
        $response = $this->get('/sitemap.xml')->assertOk();
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $locations = array_map('strval', $xml->xpath('//*[local-name()="loc"]'));
        $this->assertContains(url('/article/'.$public->id), $locations);
        $this->assertNotContains(url('/article/'.$locked->id), $locations);
        $this->assertNotContains(url('/article/'.$hidden->id), $locations);
        $this->get('/article/'.$public->id.'.md')->assertOk()->assertHeader('Link', '<'.url('/article/'.$public->id).'>; rel="canonical"');
    }

    public function test_robots_uses_absolute_sitemap_and_shared_exclusions(): void
    {
        $response = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Sitemap: '.url('/sitemap.xml'), false)->assertSee('User-agent: OAI-SearchBot')->assertSee('User-agent: GPTBot');
        $rules = $response->getContent();
        $this->assertSame(1, substr_count($rules, 'Disallow: /admin'));
        $this->assertStringContainsString('Disallow: /api/', $rules);
        $this->assertStringNotContainsString('User-agent:', substr($rules, strpos($rules, 'Disallow:')));
        $this->assertStringNotContainsString('Allow: /', $rules);
    }
}
