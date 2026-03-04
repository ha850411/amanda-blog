<?php

namespace App\Http\Controllers\Admin;

class TagController extends Controller
{
    public function tag()
    {
        return view('admin.tag')->with([
            'active' => 'tag'
        ]);
    }
}
