<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Article;

class IndexController extends Controller
{
    public function index(Request $request)
    {
        return view('index');
    }

    public function tag(int $tagId)
    {
        return view('index')->with([
            'tagId' => $tagId,
        ]);
    }

    public function article(int $id)
    {
        $article = Article::where('id', $id)
            ->with('tags')
            ->firstOrFail();

        return view('article')->with([
            'article' => $article,
        ]);
    }

    public function sitemap()
    {
        $articles = Article::where('status', 1)
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->view('sitemap', [
            'articles' => $articles
        ])->header('Content-Type', 'text/xml');
    }
}
