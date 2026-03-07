<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Article;

class IndexController extends Controller
{
    public function index(Request $request)
    {
        return view('index')->with([
            'tagId' => $request->input('tag', ''),
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
}
