<?php

namespace App\Http\Controllers;

use App\Models\About;
use App\Models\Article;
use App\Models\Social;
use App\Models\Tag;
use App\Support\ArticlePasswordCache;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    public function index(Request $request)
    {
        $siteSocialUrls = Social::where('status', 1)->pluck('url')->filter()->values()->all();

        $publisher = [
            '@type' => 'Person',
            'name' => 'Amanda',
            'url' => url('/'),
        ];
        if (! empty($siteSocialUrls)) {
            $publisher['sameAs'] = $siteSocialUrls;
        }

        $siteJsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => "Amanda's Blog",
            'url' => url('/'),
            'description' => 'Amanda 的探店、美食、生活與開箱紀錄',
            'inLanguage' => 'zh-TW',
            'publisher' => $publisher,
        ];

        return response()->view('index', [
            ...$this->layoutData(),
            ...$this->listingData($request),
            'selectedTag' => null,
            'siteJsonLd' => $siteJsonLd,
        ])->header('Cache-Control', 'public, max-age=180, stale-while-revalidate=1800');
    }

    public function tag(Request $request, int $tagId)
    {
        $selectedTag = Tag::findOrFail($tagId);

        $siteJsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => "Amanda's Blog - ".$selectedTag->name,
            'url' => route('tag', ['tagId' => $tagId]),
            'description' => "Amanda 的「{$selectedTag->name}」文章整理與分享。",
            'inLanguage' => 'zh-TW',
        ];

        return response()->view('index', [
            ...$this->layoutData(),
            ...$this->listingData($request, $tagId),
            'tagId' => $tagId,
            'selectedTag' => $selectedTag,
            'siteJsonLd' => $siteJsonLd,
        ])->header('Cache-Control', 'public, max-age=180, stale-while-revalidate=1800');
    }

    public function article(Request $request, int $id, ArticlePasswordCache $articlePasswordCache)
    {
        $article = Article::visible()->where('id', $id)
            ->with('tags')
            ->firstOrFail();
        $isPasswordVerified = $articlePasswordCache->isVerified($request, $article);

        $isProtected = (int) $article->status === 2;
        $description = $isProtected ? '這篇文章受密碼保護，請輸入密碼後閱讀。' : $article->excerpt;
        $canonicalUrl = route('article', ['id' => $article->id]);
        $articleUrl = $canonicalUrl;
        $articleImage = $isProtected ? null : $article->first_image;
        $articlePublishedAt = $article->created_at?->copy()->utc()->toAtomString();
        $articleUpdatedAt = $article->updated_at?->copy()->utc()->toAtomString();
        $tagNames = $article->tags->pluck('name')->all();

        $siteSocialUrls = Social::where('status', 1)->pluck('url')->filter()->values()->all();
        $siteAbout = About::first();
        $logoUrl = $siteAbout?->picture
            ? (str_starts_with($siteAbout->picture, 'http') ? $siteAbout->picture : url($siteAbout->picture))
            : asset('images/favicon.png');

        $firstTag = $article->tags->first();
        $breadcrumbs = [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => '首頁',
                    'item' => url('/'),
                ],
            ],
        ];

        if ($firstTag) {
            $breadcrumbs['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => $firstTag->name,
                'item' => route('tag', ['tagId' => $firstTag->id]),
            ];
            $breadcrumbs['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $article->title,
                'item' => $canonicalUrl,
            ];
        } else {
            $breadcrumbs['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => $article->title,
                'item' => $canonicalUrl,
            ];
        }

        $author = [
            '@type' => 'Person',
            'name' => 'Amanda',
            'url' => url('/'),
            'jobTitle' => 'Blogger',
        ];
        if (! empty($siteSocialUrls)) {
            $author['sameAs'] = $siteSocialUrls;
        }

        $publisher = [
            '@type' => 'Organization',
            'name' => 'Amanda',
            'url' => url('/'),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $logoUrl,
            ],
        ];

        $articleJsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $article->title,
            'description' => $description,
            'inLanguage' => 'zh-TW',
            'keywords' => implode(', ', $tagNames),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $canonicalUrl,
            ],
            'author' => $author,
            'publisher' => $publisher,
            'isPartOf' => [
                '@type' => 'Blog',
                'name' => "Amanda's Blog",
                'url' => url('/'),
            ],
            'datePublished' => $articlePublishedAt,
            'dateModified' => $articleUpdatedAt,
            'breadcrumb' => $breadcrumbs,
        ];

        if ($firstTag) {
            $articleJsonLd['articleSection'] = $firstTag->name;
        }

        if ($articleImage) {
            $articleJsonLd['image'] = [$articleImage];
        }

        $cacheControl = $isProtected
            ? 'private, no-store'
            : 'public, max-age=300, stale-while-revalidate=3600';

        return response()->view('article', [
            ...$this->layoutData(),
            'article' => $article,
            'frontendArticle' => [
                'id' => $article->id,
                'title' => $article->title,
                'content' => '', // Readable content is rendered once in the HTML body.
                'status' => $article->status,
                'created_at' => $article->created_at?->format('Y/m/d H:i:s'),
                'updated_at' => $article->updated_at?->format('Y/m/d H:i:s'),
                'tags' => $article->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ])->values()->all(),
                'is_password_verified' => $isPasswordVerified,
            ],
            'isPasswordVerified' => $isPasswordVerified,
            'description' => $description,
            'canonicalUrl' => $canonicalUrl,
            'articleUrl' => $canonicalUrl,
            'articleImage' => $articleImage,
            'articleJsonLd' => $articleJsonLd,
            'robots' => $isProtected ? 'noindex, nofollow' : 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1',
        ])->header('Cache-Control', $cacheControl);
    }

    private function layoutData(): array
    {
        return [
            'siteAbout' => About::first(),
            'siteTags' => Tag::where('parent_id', 0)->orderBy('sort')->with('children')->get(),
            'siteSocials' => Social::where('status', 1)->get(),
            'latestArticles' => Article::visible()->orderByDesc('updated_at')->orderByDesc('id')->limit(3)->get(['id', 'title', 'status']),
        ];
    }

    private function listingData(Request $request, ?int $tagId = null): array
    {
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($page === false, 404);

        $articles = Article::visible()->with('tags')
            ->when($tagId, fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->where('tag.id', $tagId)))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(5, ['*'], 'page', $page);
        abort_if($page > $articles->lastPage(), 404);
        $passwordCache = app(ArticlePasswordCache::class);

        return [
            'articles' => $articles,
            'initialArticles' => $articles->getCollection()->map(fn ($article) => $article->toListingArray($passwordCache->isVerified($request, $article)))->all(),
            'canonicalUrl' => $page === 1 ? url()->current() : url()->current().'?page='.$page,
        ];
    }

    public function robots()
    {
        return response()->view('robots')->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap()
    {
        $articles = Article::where('status', 1)
            ->orderBy('updated_at', 'desc')
            ->get();

        $tags = Tag::orderBy('sort', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->view('sitemap', [
            'articles' => $articles,
            'tags' => $tags,
        ])->header('Content-Type', 'text/xml');
    }

    public function llmsTxt()
    {
        $articles = Article::where('status', 1)
            ->with('tags')
            ->orderBy('created_at', 'desc')
            ->get();

        $tags = Tag::orderBy('sort', 'asc')->get();

        $output = "# Amanda's Blog\n\n";
        $output .= "> Amanda 的探店、美食、生活與開箱紀錄部落格\n\n";
        $output .= "## Overview\n\n";
        $output .= "本站為 Amanda 的個人部落格，分享台灣在地美食探店、各類生活開箱與真實體驗心得。\n\n";
        $output .= "## Quick Links & Machine Feeds\n\n";
        $output .= '- [RSS Feed]('.url('/rss.xml')."): 最新文章 RSS 訂閱源\n";
        $output .= '- [Full Markdown Content]('.url('/llms-full.txt')."): 全站文章完整 Markdown 彙整 (適合 AI LLM 閱讀)\n";
        $output .= '- [Sitemap]('.url('/sitemap.xml')."): XML 網站地圖\n\n";

        $output .= "## Published Articles\n\n";
        foreach ($articles as $article) {
            $url = route('article', ['id' => $article->id]);
            $excerpt = str_replace(["\r", "\n"], ' ', $article->excerpt);
            $tagsStr = $article->tags->pluck('name')->implode(', ');
            $output .= "- [{$article->title}]({$url})";
            if ($tagsStr) {
                $output .= " (標籤: {$tagsStr})";
            }
            $output .= ": {$excerpt}\n";
        }

        $output .= "\n## Categories / Tags\n\n";
        foreach ($tags as $tag) {
            $tagUrl = route('tag', ['tagId' => $tag->id]);
            $output .= "- [{$tag->name}]({$tagUrl})\n";
        }

        return response($output, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    public function llmsFullTxt()
    {
        $articles = Article::where('status', 1)
            ->with('tags')
            ->orderBy('created_at', 'desc')
            ->get();

        $output = "# Amanda's Blog - Full Content Repository for LLMs\n\n";
        $output .= "> 本文件包含全站公開文章之完整 Markdown 內容，專供 AI/LLM 摘要與檢索使用。\n\n";
        $output .= "---\n\n";

        foreach ($articles as $article) {
            $url = route('article', ['id' => $article->id]);
            $tagsStr = $article->tags->pluck('name')->implode(', ');
            $date = $article->created_at ? $article->created_at->format('Y-m-d') : '';
            $mdContent = \App\Support\MarkdownHelper::htmlToMarkdown($article->content);

            $output .= "# {$article->title}\n\n";
            $output .= "- **URL**: {$url}\n";
            $output .= "- **Date**: {$date}\n";
            if ($tagsStr) {
                $output .= "- **Tags**: {$tagsStr}\n";
            }
            $output .= "\n".$mdContent."\n\n";
            $output .= "---\n\n";
        }

        return response($output, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    public function articleMarkdown(Request $request, int $id, ArticlePasswordCache $articlePasswordCache)
    {
        $article = Article::visible()->where('id', $id)
            ->with('tags')
            ->firstOrFail();

        $isPasswordVerified = $articlePasswordCache->isVerified($request, $article);
        $url = route('article', ['id' => $article->id]);
        $date = $article->created_at ? $article->created_at->format('Y-m-d') : '';
        $tagsStr = $article->tags->pluck('name')->implode(', ');

        $output = "# {$article->title}\n\n";
        $output .= "- **URL**: {$url}\n";
        $output .= "- **Date**: {$date}\n";
        if ($tagsStr) {
            $output .= "- **Tags**: {$tagsStr}\n";
        }
        $output .= "\n";

        if ((int) $article->status === 2 && ! $isPasswordVerified) {
            $output .= "> 這篇文章受密碼保護，需驗證密碼後方可讀取完整內容。\n";
        } else {
            $output .= \App\Support\MarkdownHelper::htmlToMarkdown($article->content);
        }

        return response($output, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Link' => '<'.$url.'>; rel="canonical"',
            'X-Robots-Tag' => (int) $article->status === 2 ? 'noindex, nofollow' : 'index, follow',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function rss()
    {
        $articles = Article::where('status', 1)
            ->with('tags')
            ->orderBy('created_at', 'desc')
            ->take(30)
            ->get();

        return response()->view('rss', [
            'articles' => $articles,
        ])->header('Content-Type', 'text/xml; charset=UTF-8');
    }
}
