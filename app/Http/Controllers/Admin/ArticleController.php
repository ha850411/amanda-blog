<?php

namespace App\Http\Controllers\Admin;

class ArticleController extends Controller
{
    public function article()
    {
        return view('admin.article')->with([
            'active' => 'article'
        ]);
    }

    public function addArticle()
    {
        return view('admin.article_add')->with([
            'active' => 'article'
        ]);
    }

    public function editArticle($id)
    {
        return view('admin.article_add')->with([
            'active' => 'article',
            'id' => $id
        ]);
    }
}
