<?php

namespace App\Http\Controllers;

use App\Support\AdSense;
use Illuminate\Http\Response;

class AdsTxtController extends Controller
{
    public function __invoke(AdSense $adsense): Response
    {
        $publisherId = $adsense->publisherId();

        abort_if($publisherId === null, 404);

        return response("google.com, {$publisherId}, DIRECT, f08c47fec0942fa0\n", 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
