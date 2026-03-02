<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function login()
    {
        // 已登入則直接導向後台首頁
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.index');
        }

        return view('admin.login')->with([
            'bodyClass' => 'login'
        ]);
    }

    public function handleLogin(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('username', $credentials['username'])->first();

        if ($admin && $admin->password === $credentials['password']) {
            Auth::guard('admin')->login($admin);
            $request->session()->regenerate();

            return response()->json([
                'success' => true,
                'message' => '登入成功',
                'redirect' => route('admin.index'),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => '帳號或密碼錯誤',
        ], 401);
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

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
