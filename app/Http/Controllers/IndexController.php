<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Article;
use App\Models\Tag;

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

    public function article(int $id)
    {
        $article = Article::where('id', $id)
            ->with('tags')
            ->firstOrFail();

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
