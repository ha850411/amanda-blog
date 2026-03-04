<?php

namespace App\Http\Controllers\Api;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class LoginController extends Controller
{
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
}
