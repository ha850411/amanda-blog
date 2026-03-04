<?php

namespace App\Http\Controllers\Admin;

class AboutController extends Controller
{
    public function about()
    {

        return view('admin.about')->with([
            'active' => 'about',
        ]);
    }
}
