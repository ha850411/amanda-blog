<section class="sports-hub" id="sports-hub" data-initial-event="{{ $initialEvent }}" data-event-base="{{ url('/sports/events') }}">
    <div class="hub-header-bar">
        <div class="hub-title-group">
            <h1 class="hub-main-title">即時體育直播</h1>
            <div class="hub-clock" aria-label="台灣時間時鐘">
                <span class="hub-clock-dot" aria-hidden="true"></span>
                <span id="hub-clock-time" class="hub-clock-time">--:--:--</span>
                <span class="hub-clock-leagues">NBA / MLB</span>
                <small>台灣時間 · UTC+8</small>
            </div>
        </div>
        <div class="hub-header-actions">
            <button type="button" class="schedule-toggle-btn" id="schedule-toggle-button" aria-expanded="true" aria-controls="sports-schedule-aside" title="切換賽程欄位收合">
                <span class="toggle-icon" aria-hidden="true">◧</span>
                <span class="toggle-label" id="schedule-toggle-label">收合賽程</span>
            </button>
        </div>
    </div>
    <div class="hub-grid">
        <div class="hub-watch" id="watch-area">
            <div class="watch-heading">
                <div class="watch-info">
                    <p class="eyebrow" id="watch-meta">等待選擇比賽</p>
                    <h2 id="watch-title">選一場比賽，開始觀看</h2>
                    <p id="watch-original"></p>
                </div>
                <div class="watch-heading-actions">
                    <a class="schedule-jump" href="#sports-schedule">查看賽程 ↓</a>
                    <button type="button" class="schedule-toggle-btn watch-toggle-btn" id="schedule-toggle-watch-btn" aria-expanded="true" title="切換賽程欄位收合">
                        <span class="toggle-icon" aria-hidden="true">◧</span>
                        <span class="toggle-label" id="watch-toggle-label">收合賽程</span>
                    </button>
                </div>
            </div>
            @include('sports.partials.player', ['idle' => true, 'player' => ['mode' => 'hls', 'title' => '體育直播', 'token' => '', 'stream' => ['url' => '']]])
            <section class="hub-sources" aria-labelledby="sources-heading">
                <div class="sources-heading">
                    <div>
                        <h2 id="sources-heading">轉播來源 <span id="source-count">—</span></h2>
                    </div>
                    <button class="button" id="sources-refresh" type="button" disabled>更新來源 ↻</button>
                </div>
                <div id="hub-status" class="hub-status" role="status" aria-live="polite">從賽程選擇比賽後，這裡會顯示可用的轉播來源。</div>
                <div id="hub-source-list" class="hub-source-list"></div>
                <p class="hub-footnote">預設開啟聲音；若瀏覽器要求，點一下播放即可。切換頁籤仍持續播放。</p>
            </section>
        </div>
        <aside class="hub-schedule" id="sports-schedule-aside" aria-label="賽程與篩選">
            <div class="schedule-heading">
                <div class="schedule-heading-title">
                    <h2>賽事一覽</h2>
                    <span id="schedule-feedback" role="status" aria-live="polite"></span>
                </div>
                <button type="button" class="schedule-close-btn" id="schedule-close-btn" aria-label="收合賽程欄位" title="收合賽程">✕</button>
            </div>
            <div id="sports-schedule" tabindex="-1">@include('sports.partials.schedule')</div>
        </aside>
    </div>
    <div class="hub-floating-return" id="hub-floating-return" hidden>
        <span class="floating-live-dot" aria-hidden="true"></span>
        <div class="floating-text">
            <strong id="floating-match-title">正在播放</strong>
            <small id="floating-source-info">點此返回播放器</small>
        </div>
        <button type="button" class="button primary" id="floating-return-btn">↑ 返回播放器</button>
    </div>
    <noscript><div class="notice">同頁播放需要 JavaScript；你仍可透過賽程中的連結查看來源。</div></noscript>
</section>
