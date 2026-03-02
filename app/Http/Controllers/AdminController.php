<?php

namespace App\Http\Controllers;

class AdminController extends Controller
{
    public function login()
    {
        return view('admin.login')->with([
            'bodyClass' => 'login'
        ]);
    }

    public function index()
    {
        return view('admin.index')->with([
            'active' => 'index'
        ]);
    }

    public function about()
    {
        return view('admin.about')->with([
            'active' => 'about'
        ]);
    }

    public function tag()
    {
        return view('admin.tag')->with([
            'active' => 'tag'
        ]);
    }

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

    public function social()
    {
        return view('admin.social')->with([
            'active' => 'social'
        ]);
    }

    public function logout()
    {
        // todo logout
        return redirect()->route('admin.login');
    }
}
