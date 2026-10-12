@extends('sports.layout')
@section('title', $player ? $player['title'].' · 播放器' : '播放器連結已過期')
@push('scripts')
    <script src="{{ asset($player && $player['mode'] === 'hls' ? 'js/sports-live-player.js' : 'js/sports-player.js') }}?v=art-2" defer></script>
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
        <section class="sports-player" id="sports-player" aria-label="直播播放器" data-mode="{{ $player['mode'] }}"
            @if ($player['mode'] === 'hls') data-source-url="{{ route('sports.hls.playlist', $player['token'], false) }}" data-hls-library="{{ asset('vendor/hls/hls-1.7.3.min.js') }}" data-ui-library="{{ asset('vendor/artplayer/artplayer-5.4.0.js') }}" data-ui-language="{{ asset('vendor/artplayer/zh-tw-5.4.0.js') }}"
            @else data-embed-url="{{ $player['stream']['url'] }}" @endif>
            <div class="player-topbar">
                <strong class="player-brand">Easonn <span>SPORTS</span></strong>
                <span class="player-state" id="player-state" role="status">{{ $player['mode'] === 'hls' ? '正在連接直播' : '準備播放' }}</span>
            </div>
            <div class="video-stage">
                @if ($player['mode'] === 'hls')
                    <div id="sports-artplayer" aria-label="{{ $player['title'] }} 直播影片"></div>
                @else
                    <iframe id="sports-embed" title="{{ $player['title'] }} 原站直播播放器" sandbox="allow-scripts allow-same-origin allow-presentation" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen hidden></iframe>
                @endif
                <div class="player-overlay" id="player-overlay" @if ($player['mode'] === 'hls') hidden @endif>
                    <span class="play-symbol" aria-hidden="true">▷</span>
                    <p id="player-message" role="status" aria-live="polite">{{ $player['mode'] === 'hls' ? '自動畫質會依網路與裝置調整。點選播放開始觀看。' : '點選播放，在此頁載入原站播放器。' }}</p>
                    <button class="button primary" type="button" id="player-start">播放直播</button>
                </div>
            </div>
            @if ($player['mode'] === 'hls')
                <div class="player-summary">
                    <span class="live-label"><i aria-hidden="true"></i> LIVE</span>
                    <span id="player-metrics" class="player-metrics">等待影片資訊</span>
                    <span class="player-controls-hint">畫質與播放模式 · 播放器設定</span>
                </div>
            @endif
            <div class="player-toolbar">
                <div class="player-toolbar-group">
                    <button class="button" id="player-retry" type="button">重新載入</button>
                    <button class="button" id="player-stop" type="button" disabled>停止播放</button>
                    <a class="button" href="{{ $player['stream']['url'] }}" target="_blank" rel="noopener noreferrer nofollow">原連結 ↗</a>
                </div>
                @if ($player['mode'] !== 'hls')<div class="player-toolbar-group">
                    <button class="button" id="player-theater" type="button" aria-pressed="false">網頁全螢幕</button>
                    <button class="button" id="player-fullscreen" type="button" aria-pressed="false">螢幕全螢幕</button>
                </div>@else<span class="player-shortcuts">Space 播放／暫停 · ↑↓ 音量 · F 全螢幕</span>@endif
            </div>
            <p id="player-feedback" class="player-feedback" role="status" aria-live="polite"></p>
        </section>
        <p class="status-note">{{ $player['mode'] === 'hls' ? '畫質依來源提供；低延遲模式較容易受網路波動影響。省流量模式會在來源提供多畫質時降低畫質。暫停或離開頁面會停止載入影音。' : '使用原站播放列控制播放、暫停及音量。' }} 網頁全螢幕可填滿分頁，按 Esc 還原。</p>
        <noscript><div class="notice warning">站內播放器需要 JavaScript。請啟用 JavaScript，或使用原連結。</div></noscript>
    @endif
@endsection
