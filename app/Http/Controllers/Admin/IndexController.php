<?php

namespace App\Http\Controllers\Admin;

class IndexController extends Controller
{
    public function index()
    {
        $today = \Carbon\CarbonImmutable::today('Asia/Taipei')->toDateString();

        return view('admin.index')->with([
            'active' => 'index',
            'analyticsToday' => $today,
            'analyticsStart' => $today,
        ]);
    }
}
