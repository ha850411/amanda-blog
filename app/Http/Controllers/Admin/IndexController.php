<?php

namespace App\Http\Controllers\Admin;

class IndexController extends Controller
{
    public function index()
    {
        return view('admin.index')->with([
            'active' => 'index',
            'analyticsToday' => \Carbon\CarbonImmutable::today('Asia/Taipei')->toDateString(),
            'analyticsStart' => \Carbon\CarbonImmutable::today('Asia/Taipei')->subDays(6)->toDateString(),
        ]);
    }
}
