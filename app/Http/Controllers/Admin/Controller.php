<?php

namespace App\Http\Controllers\Admin;

use App\Models\About;

abstract class Controller
{
    //
    public function __construct()
    {
        $about = About::first();

        view()->share([
            'about' => $about,
        ]);
    }
}
