<?php

namespace App\Http\Controllers;

use App\Services\SportsPlayerService;

class SportsPlayerController extends Controller
{
    public function show(string $token, SportsPlayerService $players)
    {
        $player = $players->find($token);

        return response()->view('sports.player', compact('player'), $player ? 200 : 410)
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }
}
