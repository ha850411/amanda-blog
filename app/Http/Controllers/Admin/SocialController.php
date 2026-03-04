<?php

namespace App\Http\Controllers\Admin;

class SocialController extends Controller
{
    public function social()
    {
        return view('admin.social')->with([
            'active' => 'social'
        ]);
    }
}
