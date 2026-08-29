<?php

namespace App\Http\Controllers\Admin;

use App\Models\Article;
use App\Models\Tag;

class ArticleController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $tags = Tag::where('parent_id', 0)
            ->orderBy('sort', 'asc')
            ->with('children')
            ->get();

        view()->share([
            'tags' => $tags,
        ]);
    }

    public function article()
    {
        return view('admin.article')->with([
            'active' => 'article',
        ]);
    }

    public function addArticle()
    {
        return view('admin.article_add')->with([
            'active' => 'article',
            'article' => null,
        ]);
    }

    public function editArticle($id)
    {
        $article = Article::with('tags')->find($id);

        return view('admin.article_add')->with([
            'active' => 'article',
            'article' => $article,
        ]);
    }
}
