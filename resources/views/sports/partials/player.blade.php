@php($idle = $idle ?? false)
        <section class="sports-player" id="sports-player" aria-label="直播播放器" data-mode="{{ $player['mode'] }}"
            data-media-adapter="{{ $player['media_adapter'] ?? '' }}" data-adapter-library="{{ asset('js/gks-media.js') }}?v=4"
            @if ($player['mode'] === 'hls') data-source-url="{{ $idle ? '' : route('sports.hls.playlist', $player['token'], false) }}" data-hls-library="{{ asset('vendor/hls/hls-1.7.3.min.js') }}" data-ui-library="{{ asset('vendor/artplayer/artplayer-5.4.0.js') }}" data-ui-language="{{ asset('vendor/artplayer/zh-tw-5.4.0.js') }}"
            @else data-embed-url="{{ $player['stream']['url'] }}" @endif>
            <div class="player-topbar">
                <strong class="player-brand">Easonn <span>SPORTS</span></strong>
                <span class="player-state" id="player-state" role="status">{{ $idle ? '等待選擇比賽' : ($player['mode'] === 'hls' ? '正在連接直播' : '準備播放') }}</span>
            </div>
            <div class="video-stage">
                @if ($player['mode'] === 'hls')
                    <div id="sports-artplayer" aria-label="{{ $player['title'] }} 直播影片"></div>
                @else
                    <iframe id="sports-embed" title="{{ $player['title'] }} 原站直播播放器" sandbox="allow-scripts allow-same-origin allow-presentation" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen hidden></iframe>
                @endif
                <div class="player-overlay" id="player-overlay" @if ($player['mode'] === 'hls' && !$idle) hidden @endif>
                    <span class="play-symbol" aria-hidden="true">▷</span>
                    <p id="player-message" role="status" aria-live="polite">{{ $idle ? '你的下一場精彩，從賽程開始。' : ($player['mode'] === 'hls' ? '自動畫質會依網路與裝置調整。點選播放開始觀看。' : '點選播放，在此頁載入原站播放器。') }}</p>
                    <button class="button primary" type="button" id="player-start" @disabled($idle)>播放直播</button>
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
                    <button class="button" id="player-retry" type="button" @disabled($idle)>重新載入</button>
                    <button class="button" id="player-stop" type="button" disabled>停止播放</button>
                    <a class="button" id="player-original" href="{{ $player['stream']['url'] }}" target="_blank" rel="noopener noreferrer nofollow" @if ($idle) hidden @endif>原連結 ↗</a>
                </div>
                @if ($player['mode'] !== 'hls')<div class="player-toolbar-group">
                    <button class="button" id="player-theater" type="button" aria-pressed="false">網頁全螢幕</button>
                    <button class="button" id="player-fullscreen" type="button" aria-pressed="false">螢幕全螢幕</button>
                </div>@else<span class="player-shortcuts">Space 播放／暫停 · ↑↓ 音量 · F 全螢幕</span>@endif
            </div>
            <p id="player-feedback" class="player-feedback" role="status" aria-live="polite"></p>
        </section>
