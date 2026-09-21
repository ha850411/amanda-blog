<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdSenseTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLISHER_ID = 'pub-1234567890123456';

    private const SCRIPT_URL = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-'.self::PUBLISHER_ID;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('adsense.publisher_id', self::PUBLISHER_ID);
        config()->set('adsense.enabled', true);
    }

    public function test_public_content_has_one_ad_script_in_the_head(): void
    {
        $article = Article::factory()->create(['status' => 1]);
        $tag = Tag::factory()->create();

        foreach (['/', '/tag/'.$tag->id, '/article/'.$article->id] as $url) {
            $response = $this->get($url)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);

            $this->assertSame(1, $xpath->query('//head/script[@src="'.self::SCRIPT_URL.'"]')->length);
            $this->assertSame(1, $xpath->query('//script[@src="'.self::SCRIPT_URL.'"]')->length);
            $response->assertSee('name="google-adsense-account" content="ca-'.self::PUBLISHER_ID.'"', false);
        }
    }

    public function test_verification_and_ads_txt_work_before_enabling_ads(): void
    {
        config()->set('adsense.enabled', false);

        $this->get('/')
            ->assertOk()
            ->assertSee('name="google-adsense-account" content="ca-'.self::PUBLISHER_ID.'"', false)
            ->assertDontSee('adsbygoogle.js', false);

        $this->get('/ads.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertContent('google.com, '.self::PUBLISHER_ID.", DIRECT, f08c47fec0942fa0\n");
    }

    public function test_client_id_format_is_normalized_for_ads_txt(): void
    {
        config()->set('adsense.publisher_id', ' ca-'.self::PUBLISHER_ID.' ');

        $this->get('/')->assertOk()->assertSee(self::SCRIPT_URL, false);
        $this->get('/ads.txt')
            ->assertOk()
            ->assertContent('google.com, '.self::PUBLISHER_ID.", DIRECT, f08c47fec0942fa0\n");
    }

    #[DataProvider('invalidPublisherIds')]
    public function test_missing_or_invalid_ids_do_not_publish_advertising_configuration(?string $id): void
    {
        config()->set('adsense.publisher_id', $id);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('google-adsense-account', false)
            ->assertDontSee('adsbygoogle.js', false);
        $this->get('/ads.txt')->assertNotFound();
    }

    public static function invalidPublisherIds(): array
    {
        return [
            'missing' => [null],
            'blank' => [''],
            'wrong length' => ['pub-1234'],
            'injected content' => [self::PUBLISHER_ID.'"><script>alert(1)</script>'],
        ];
    }

    public function test_protected_hidden_and_non_content_pages_do_not_load_ads(): void
    {
        $protected = Article::factory()->create(['status' => 2, 'password' => 'secret']);
        $hidden = Article::factory()->create(['status' => 0]);

        foreach (['/article/'.$protected->id, '/article/'.$hidden->id, '/privacy', '/admin/login'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('adsbygoogle.js', false);
        }

        $this->get('/article/999999')->assertNotFound()->assertDontSee('adsbygoogle.js', false);
    }

    public function test_privacy_policy_and_footer_link_are_available_without_javascript(): void
    {
        $response = $this->get('/privacy')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);

        $this->assertSame(1, $xpath->query('//main[not(ancestor::template)]//h1')->length);
        $this->assertSame(1, $xpath->query('//footer[not(ancestor::template)]//a[@href="'.route('privacy').'"]')->length);
        $this->assertSame(0, $xpath->query('//body[contains(@class, "page-loading")]')->length);
        $response->assertSee('https://myadcenter.google.com/', false)
            ->assertSee('https://optout.aboutads.info/', false)
            ->assertSee('mailto:summer.hung222@gmail.com', false);

        $this->get('/sitemap.xml')->assertOk()->assertSee(route('privacy'), false);
    }
}
