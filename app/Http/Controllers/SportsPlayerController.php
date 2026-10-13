<?php

namespace App\Http\Controllers;

use App\Services\SportsHlsService;
use App\Services\SportsPlayerService;
use Illuminate\Http\Request;
use Throwable;

class SportsPlayerController extends Controller
{
    public function show(string $token, SportsPlayerService $players)
    {
        $player = $players->find($token);

        return response()->view('sports.player', compact('player'), $player ? 200 : 410)
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }

    public function playlist(string $token, SportsPlayerService $players, SportsHlsService $hls)
    {
        $source = $this->source($token, $players, $hls);

        return $this->relay($token, $source, $source['manifest_url'], 'playlist', $hls);
    }

    public function resource(Request $request, string $token, SportsPlayerService $players, SportsHlsService $hls)
    {
        $source = $this->source($token, $players, $hls);
        $ticket = $request->query('ticket');
        $resource = is_string($ticket) ? $hls->resource($ticket, $token, $source) : null;
        abort_unless($resource, 404);

        return $this->relay($token, $source, $resource['url'], $resource['kind'], $hls);
    }

    private function source(string $token, SportsPlayerService $players, SportsHlsService $hls): array
    {
        $player = $players->find($token);
        abort_unless($player && $player['mode'] === 'hls', 410);
        try {
            $source = $hls->source($player, true);
        } catch (Throwable) {
            abort(503, '來源暫時無法載入，請稍後重試。');
        }
        abort_unless($source, 410);

        return $source;
    }

    private function relay(string $token, array $source, string $url, string $kind, SportsHlsService $hls)
    {
        try {
            $body = $hls->load($source, $url, $kind);
            $body = $hls->rewrite($body, $url, $source, $token);

            return response($body)->header('Content-Type', 'application/vnd.apple.mpegurl')
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow');
        } catch (Throwable) {
            return response('來源暫時無法載入，請稍後重試。', 503)->header('Retry-After', '2')->header('Cache-Control', 'no-store');
        }
    }
}
