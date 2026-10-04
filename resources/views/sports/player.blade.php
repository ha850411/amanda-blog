@extends('sports.layout')
@section('title', $player ? $player['title'].' · 播放器' : '播放器連結已過期')
@push('scripts')
    <script src="{{ asset('js/sports-player.js') }}?v=client-1" defer></script>
@endpush
@section('content')
    @if (!$player)
        <div class="empty"><h1>播放器連結已過期或無法使用</h1><p>請回到賽程，重新選擇來源。</p><a class="button primary" href="{{ route('sports.index') }}">返回賽程</a></div>
    @else
        <a class="back-link" href="{{ route('sports.show', $player['event_id']) }}">← 返回賽事・切換來源</a>
        <section class="player-heading">
            <p class="eyebrow">{{ $player['stream']['platform'] }} / {{ $player['stream']['language'] }} / 頻道 {{ $player['stream']['number'] }}</p>
            <h1 class="detail-title">{{ $player['title'] }}</h1>
        </section>
        <section class="sports-player" id="sports-player" aria-label="直播播放器" data-embed-url="{{ $player['stream']['url'] }}">
            <div class="player-topbar">
                <strong class="player-brand">Easonn <span>SPORTS</span></strong>
                <span class="player-state" id="player-state">準備播放</span>
            </div>
            <div class="video-stage">
                <iframe id="sports-embed" title="{{ $player['title'] }} 原站直播播放器" sandbox="allow-scripts allow-same-origin allow-presentation" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen hidden></iframe>
                <div class="player-overlay" id="player-overlay">
                    <span class="play-symbol" aria-hidden="true">▷</span>
                    <p id="player-message" role="status" aria-live="polite">點選播放，在此頁載入原站播放器。</p>
                    <button class="button primary" type="button" id="player-start">播放直播</button>
                </div>
            </div>
            <div class="player-toolbar">
                <div class="player-toolbar-group">
                    <button class="button" id="player-retry" type="button">重新載入</button>
                    <button class="button" id="player-stop" type="button" disabled>停止播放</button>
                    <a class="button" href="{{ $player['stream']['url'] }}" target="_blank" rel="noopener noreferrer nofollow">原連結 ↗</a>
                </div>
                <div class="player-toolbar-group">
                    <button class="button" id="player-theater" type="button" aria-pressed="false">網頁全螢幕</button>
                    <button class="button" id="player-fullscreen" type="button" aria-pressed="false">螢幕全螢幕</button>
                </div>
            </div>
            <p id="player-feedback" class="player-feedback" role="status" aria-live="polite"></p>
        </section>
        <p class="status-note">使用原站播放列控制播放、暫停及音量。網頁全螢幕可填滿分頁，按「離開網頁全螢幕」還原。若來源無法載入，請切換來源或使用「原連結」。</p>
        <noscript><div class="notice warning">站內播放器需要 JavaScript。請啟用 JavaScript，或使用原連結。</div></noscript>
    @endif
@endsection
