<?php

namespace App\Http\Controllers\Admin;

class BadgeController extends Controller
{
    public function badge()
    {
        return view('admin.badge')->with([
            'active' => 'badge',
        ]);
    }
}
