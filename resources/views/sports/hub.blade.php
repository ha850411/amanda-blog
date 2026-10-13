<section class="sports-hub" id="sports-hub" data-initial-event="{{ $initialEvent }}" data-event-base="{{ url('/sports/events') }}">
    <div class="hub-heading">
        <div><p class="eyebrow">THE GAME IS HERE</p><h1>每場精彩，<span>都在這裡。</span></h1></div>
        <div class="hub-clock"><span class="hub-clock-dot"></span> NBA / MLB <small>台灣時間 · UTC+8</small></div>
    </div>
    <div class="hub-grid">
        <div class="hub-watch" id="watch-area">
            <div class="watch-heading">
                <div><p class="eyebrow" id="watch-meta">你的觀賽席</p><h2 id="watch-title">選一場比賽，開始觀看</h2><p id="watch-original">賽程、直播與轉播來源，一頁掌握。</p></div>
                <a class="schedule-jump" href="#sports-schedule">查看賽程 ↓</a>
            </div>
            @include('sports.partials.player', ['idle' => true, 'player' => ['mode' => 'hls', 'title' => '體育直播', 'token' => '', 'stream' => ['url' => '']]])
            <section class="hub-sources" aria-labelledby="sources-heading">
                <div class="sources-heading"><div><p class="eyebrow">STREAM SOURCES</p><h2 id="sources-heading">轉播來源 <span id="source-count">—</span></h2></div><button class="button" id="sources-refresh" type="button" disabled>更新來源 ↻</button></div>
                <div id="hub-status" class="hub-status" role="status" aria-live="polite">從賽程選擇比賽後，這裡會顯示可用的轉播來源。</div>
                <div id="hub-source-list" class="hub-source-list"></div>
                <p class="hub-footnote">預設開啟聲音；若瀏覽器要求，點一下播放即可。切換頁籤仍持續播放。</p>
            </section>
        </div>
        <aside class="hub-schedule" aria-label="賽程與篩選">
            <div class="schedule-heading"><div><p class="eyebrow">SCHEDULE</p><h2>賽事一覽</h2></div><span id="schedule-feedback" role="status" aria-live="polite"></span></div>
            <div id="sports-schedule" tabindex="-1">@include('sports.partials.schedule')</div>
        </aside>
    </div>
    <noscript><div class="notice">同頁播放需要 JavaScript；你仍可透過賽程中的連結查看來源。</div></noscript>
</section>
