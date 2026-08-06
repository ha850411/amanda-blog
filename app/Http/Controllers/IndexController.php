<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Article;
use App\Models\Tag;
use App\Support\ArticlePasswordCache;

class IndexController extends Controller
{
    public function index(Request $request)
    {
        $siteJsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => "Amanda's Blog",
            'url' => url('/'),
            'description' => 'Amanda 的探店、美食、生活與開箱紀錄',
            'inLanguage' => 'zh-TW',
            'publisher' => [
                '@type' => 'Person',
                'name' => 'Amanda',
            ],
        ];

        return view('index')->with([
            'selectedTag' => null,
            'siteJsonLd' => $siteJsonLd,
        ]);
    }

    public function tag(int $tagId)
    {
        $selectedTag = Tag::findOrFail($tagId);

        $siteJsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => "Amanda's Blog - " . $selectedTag->name,
            'url' => route('tag', ['tagId' => $tagId]),
            'description' => "Amanda 的「{$selectedTag->name}」文章整理與分享。",
            'inLanguage' => 'zh-TW',
        ];

        return view('index')->with([
            'tagId' => $tagId,
            'selectedTag' => $selectedTag,
            'siteJsonLd' => $siteJsonLd,
        ]);
    }

    public function article(Request $request, int $id, ArticlePasswordCache $articlePasswordCache)
    {
        $article = Article::where('id', $id)
            ->with('tags')
            ->firstOrFail();
        $isPasswordVerified = $articlePasswordCache->isVerified($request, $article);

        $description = $article->excerpt;
        $articleUrl = url()->current();
        $articleImage = $article->first_image;
        $articlePublishedAt = $article->created_at ? $article->created_at->tz('UTC')->toAtomString() : null;
        $articleUpdatedAt = $article->updated_at ? $article->updated_at->tz('UTC')->toAtomString() : null;
        $tagNames = $article->tags->pluck('name')->all();

        $articleJsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $article->title,
            'description' => $description,
            'articleBody' => $description,
            'inLanguage' => 'zh-TW',
            'keywords' => implode(', ', $tagNames),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $articleUrl,
            ],
            'author' => [
                '@type' => 'Person',
                'name' => 'Amanda',
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'Amanda',
            ],
            'isPartOf' => [
                '@type' => 'Blog',
                'name' => "Amanda's Blog",
                'url' => url('/'),
            ],
            'speakable' => [
                '@type' => 'SpeakableSpecification',
                'cssSelector' => ['.article-content', 'h1'],
            ],
            'datePublished' => $articlePublishedAt,
            'dateModified' => $articleUpdatedAt,
        ];

        if ($articleImage) {
            $articleJsonLd['image'] = [$articleImage];
        }

        return view('article')->with([
            'article' => $article,
            'frontendArticle' => [
                'id' => $article->id,
                'title' => $article->title,
                'content' => (int) $article->status === 2 && !$isPasswordVerified ? '' : $article->content,
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
            'articleUrl' => $articleUrl,
            'articleImage' => $articleImage,
            'articleJsonLd' => $articleJsonLd,
        ]);
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
        $output .= "- [RSS Feed](" . url('/rss.xml') . "): 最新文章 RSS 訂閱源\n";
        $output .= "- [Full Markdown Content](" . url('/llms-full.txt') . "): 全站文章完整 Markdown 彙整 (適合 AI LLM 閱讀)\n";
        $output .= "- [Sitemap](" . url('/sitemap.xml') . "): XML 網站地圖\n\n";

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
            $output .= "\n" . $mdContent . "\n\n";
            $output .= "---\n\n";
        }

        return response($output, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    public function articleMarkdown(Request $request, int $id, ArticlePasswordCache $articlePasswordCache)
    {
        $article = Article::where('id', $id)
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

        if ((int) $article->status === 2 && !$isPasswordVerified) {
            $output .= "> 這篇文章受密碼保護，需驗證密碼後方可讀取完整內容。\n";
        } else {
            $output .= \App\Support\MarkdownHelper::htmlToMarkdown($article->content);
        }

        return response($output, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
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
