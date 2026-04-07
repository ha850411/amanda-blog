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
        return view('index')->with([
            'selectedTag' => null,
        ]);
    }

    public function tag(int $tagId)
    {
        $selectedTag = Tag::findOrFail($tagId);

        return view('index')->with([
            'tagId' => $tagId,
            'selectedTag' => $selectedTag,
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
        $articleJsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $article->title,
            'description' => $description,
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
}
