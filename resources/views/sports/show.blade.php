@extends('sports.layout')
@section('title', $match['translated_title'])
@section('content')
    <a class="back-link" href="{{ route('sports.index', ['league' => $match['league'], 'sport' => $match['league'] ? null : $match['category']]) }}">← 返回賽程</a>
    <section class="detail-hero">
        <p class="eyebrow">{{ $match['league'] ?? $match['category_label'] }} / {{ $match['date'] }} / {{ $match['time'] }} 台灣時間</p>
        <h1 class="detail-title">{{ $match['translated_title'] }}</h1>
        @if ($match['translated_title'] !== $match['title'])<p class="original-title" lang="en">{{ $match['title'] }}</p>@endif
        @include('sports.teams')
        <p class="status-note">{{ \Carbon\CarbonImmutable::parse($match['start_at'])->isAfter($now) ? '尚未到排定開賽時間，部分來源可能於開賽前才開放。' : '已到排定開賽時間；是否仍在直播，請以播放器的實際內容為準。' }}</p>
    </section>
    @if ($schedule['stale'] || $streams['stale'])<div class="notice warning" role="status">來源更新暫時失敗，部分資訊使用最近一次的快取，連結可能已變動。</div>@endif
    @if ($streams['failed_sources'])<div class="notice warning" role="status">暫時無法取得 {{ implode('、', $streams['failed_sources']) }} 的來源，其他可取得的連結仍列於下方。</div>@endif
    @if ($east['unavailable'])<div class="notice warning" role="status">Streameast 暫時無法更新，已取得的其他平台來源仍可使用。</div>@endif
    <div class="section-heading"><div><p class="eyebrow">STREAM SOURCES</p><h2>轉播來源 <span>{{ count($streams['data']) }}</span></h2></div><a class="button" href="{{ $match['source_url'] }}" target="_blank" rel="noopener noreferrer nofollow">原站賽事頁 ↗</a></div>
    <p class="status-note">請使用原連結觀看；已確認支援站內播放的來源才會提供「站內播放器」。HD 為來源標示的畫質。</p>
    @if ($east['matched'])
        <p class="status-note">已配對 Streameast 同場賽事：<a href="{{ $east['source_url'] }}" target="_blank" rel="noopener noreferrer nofollow">Streameast 賽事頁 ↗</a>@if ($east['updated_at']) · 更新於 {{ \Carbon\CarbonImmutable::parse($east['updated_at'])->setTimezone(config('sports.timezone'))->format('m/d H:i:s') }}@endif @if (!$east['data']) · 目前尚未取得支援的播放器連結。@endif</p>
    @elseif (!$east['unavailable'] && config('streameast.enabled'))
        <p class="status-note">Streameast 目前沒有可確認為同場的來源。</p>
    @endif
    <div class="source-grid">
        @forelse ($streams['data'] as $stream)
            <article class="source-card">
                <span class="source-number">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                <span class="source-info"><strong>{{ $stream['language'] }}</strong><small>{{ $stream['platform'] }} / {{ strtoupper($stream['provider']) }} · 頻道 {{ $stream['number'] }}</small></span>
                <span class="quality">{{ $stream['hd'] === null ? '畫質未標示' : ($stream['hd'] ? 'HD' : '一般畫質') }}</span>
                <div class="source-actions">
                    <a class="button" href="{{ $stream['url'] }}" target="_blank" rel="noopener noreferrer nofollow" aria-label="{{ $stream['platform'] }} {{ $stream['language'] }} 原連結">原連結 ↗</a>
                    @if ($stream['player_url'])
                        <a class="button primary" href="{{ $stream['player_url'] }}" target="_blank" rel="noopener" aria-label="{{ $stream['platform'] }} {{ $stream['language'] }} 站內播放器">站內播放器 ↗</a>
                    @endif
                </div>
            </article>
        @empty
            <div class="empty"><strong>{{ $streams['failed_sources'] ? '目前無法取得播放器連結' : '目前尚未提供播放器連結' }}</strong><p>可稍後重新整理，或至原站查看。</p></div>
        @endforelse
    </div>
    <p class="status-note">@if ($streams['updated_at'])來源更新於 {{ \Carbon\CarbonImmutable::parse($streams['updated_at'])->setTimezone(config('sports.timezone'))->format('m/d H:i:s') }}。@endif <a href="{{ request()->url() }}">重新整理 ↻</a></p>
@endsection
