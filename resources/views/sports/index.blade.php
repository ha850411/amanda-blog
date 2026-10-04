@extends('sports.layout')
@section('content')
    <section class="hero">
        <div><p class="eyebrow">THE GAME STARTS HERE / 賽事一覽</p><h1>找到你的<span>下一場比賽。</span></h1><p class="intro">中英隊名、球隊標誌與轉播來源，一眼掌握賽程。</p></div>
        <div class="hero-stat"><strong>{{ $total }}</strong><span>{{ !empty($filters['date']) ? '指定日期' : ($filters['range'] === 'history' ? '歷史賽事' : '今天起') }} · {{ $sportCount }} 種運動</span><small>TAIPEI · UTC+8</small></div>
    </section>

    @if ($schedule['unavailable'])
        <div class="notice warning" role="alert">目前無法取得賽程，請稍後重新整理。<a href="https://sportsurge.pro/Fullschedule/" target="_blank" rel="noopener noreferrer">查看原站賽程 ↗</a></div>
    @elseif ($schedule['stale'])
        <div class="notice warning" role="status">來源暫時無法更新，以下顯示最近一次取得的賽程。更新時間見下方。</div>
    @endif

    <nav class="quick-filters" aria-label="賽程範圍">
        @foreach (['current' => '今天起', 'history' => '歷史賽事'] as $range => $label)
            <a class="chip {{ empty($filters['date']) && $filters['range'] === $range ? 'selected' : '' }}" href="{{ route('sports.index', array_merge(array_diff_key($filters, array_flip(['date'])), ['range' => $range])) }}" @if (empty($filters['date']) && $filters['range'] === $range) aria-current="page" @endif>{{ $label }} <span>{{ $rangeCounts[$range] }}</span></a>
        @endforeach
    </nav>
    <nav class="quick-filters" aria-label="快速選擇聯盟">
        <a class="chip {{ empty($filters['league']) && empty($filters['sport']) ? 'selected' : '' }}" href="{{ route('sports.index', array_diff_key($filters, array_flip(['league', 'sport']))) }}">全部賽事 <span>{{ $total }}</span></a>
        @foreach (['MLB' => '美國職棒', 'NBA' => '美國職籃'] as $league => $label)
            <a class="chip {{ ($filters['league'] ?? '') === $league ? 'selected' : '' }}" href="{{ route('sports.index', array_merge(array_diff_key($filters, array_flip(['sport'])), ['league' => $league])) }}">{{ $league }} {{ $label }} <span>{{ $leagueCounts[$league] }}</span></a>
        @endforeach
    </nav>
    <form class="filters" action="{{ route('sports.index') }}" method="get" aria-label="篩選賽程">
        <input type="hidden" name="range" value="{{ $filters['range'] }}">
        @if (!empty($filters['league']))<input type="hidden" name="league" value="{{ $filters['league'] }}">@endif
        <label>運動分類<select name="sport"><option value="">所有運動</option>@foreach ($categories as $key => $category)<option value="{{ $key }}" @selected(($filters['sport'] ?? '') === $key)>{{ $category['label'] }}（{{ $category['count'] }}）</option>@endforeach</select></label>
        <label>台灣日期<input type="date" name="date" value="{{ $filters['date'] ?? '' }}"></label>
        <label>開賽時間<select name="status"><option value="">全部時間</option><option value="upcoming" @selected(($filters['status'] ?? '') === 'upcoming')>尚未到開賽時間</option><option value="started" @selected(($filters['status'] ?? '') === 'started')>已到開賽時間</option></select></label>
        <label class="search-label">搜尋隊伍／賽事<input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="例如：釀酒人、湖人、Padres" maxlength="100"></label>
        <button type="submit" class="button primary">篩選賽事</button>
        <a class="reset" href="{{ route('sports.index') }}">清除</a>
    </form>
    <div class="results-bar"><p>顯示 <strong>{{ $count }}</strong> 場賽事</p><p>@if ($schedule['updated_at'])更新於 {{ \Carbon\CarbonImmutable::parse($schedule['updated_at'])->setTimezone(config('sports.timezone'))->format('m/d H:i:s') }}@endif <a href="{{ request()->fullUrl() }}">重新整理 ↻</a></p></div>
    <p class="status-note">@if (!empty($filters['date']))目前顯示 {{ $filters['date'] }} 的台灣賽程。@elseif ($filters['range'] === 'history')歷史賽事依日期由近到遠排列；僅列出來源仍提供的舊賽程。@else目前顯示台灣時間今天起的賽程，包含今天已到開賽時間的比賽。@endif</p>
    <p class="status-note">狀態依排定時間顯示；已到開賽時間不代表仍在直播。MLB、NBA 依雙方隊名辨識，含熱身賽。</p>

    @forelse ($matches as $date => $dayMatches)
        @php($day = \Carbon\CarbonImmutable::parse($date, config('sports.timezone')))
        <section class="day-section" aria-label="{{ $date }} 賽程">
            <div class="day-heading"><h2><time datetime="{{ $date }}">{{ $day->format($day->year === $now->year ? 'm / d' : 'Y / m / d') }}</time> <span>{{ $date === $now->format('Y-m-d') ? '今天 · ' : '' }}星期{{ ['日', '一', '二', '三', '四', '五', '六'][$day->dayOfWeek] }}</span></h2><span>{{ $dayMatches->count() }} 場</span></div>
            <div class="match-grid">
                @foreach ($dayMatches as $match)
                    <article class="match-card" aria-label="{{ $match['translated_title'] }}">
                        <div class="match-top"><span class="sport-label">{{ $match['league'] ?? $match['category_label'] }}</span><span class="time"><time datetime="{{ $match['start_at'] }}">{{ $match['time'] }}</time><span class="status-dot {{ \Carbon\CarbonImmutable::parse($match['start_at'])->isAfter($now) ? '' : 'started' }}"></span>{{ \Carbon\CarbonImmutable::parse($match['start_at'])->isAfter($now) ? '即將開賽' : '已到開賽時間' }}</span></div>
                        @include('sports.teams')
                        <div class="match-bottom"><span>Sportsurge {{ count($match['sources']) }} 組 · 點入查詢其他平台</span><a href="{{ route('sports.show', $match['id']) }}" aria-label="查看 {{ $match['translated_title'] }} 的來源">查看來源 <span aria-hidden="true">↗</span></a></div>
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        @unless ($schedule['unavailable'])<div class="empty"><strong>沒有符合條件的賽程</strong><p>試試其他日期、運動分類，或清除搜尋條件。</p><a class="button" href="{{ route('sports.index') }}">查看今天起的賽事</a></div>@endunless
    @endforelse
@endsection
