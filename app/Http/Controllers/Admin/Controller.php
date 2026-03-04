<?php

namespace App\Http\Controllers\Admin;

use App\Models\About;

abstract class Controller
{
    //
    public function __construct()
    {
        $about = About::first();

        $amazonUrl = "https://" . env('AWS_BUCKET') . ".s3." . env('AWS_DEFAULT_REGION') . ".amazonaws.com";

        view()->share([
            'about' => $about,
            'amazonUrl' => $amazonUrl
        ]);
    }
}
