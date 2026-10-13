@extends('sports.layout')
@section('title', $player ? $player['title'].' · 播放器' : '播放器連結已過期')
@push('scripts')
    <script src="{{ asset($player && $player['mode'] === 'hls' ? 'js/sports-live-player.js' : 'js/sports-player.js') }}?v=gks-smart-2" defer></script>
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
        @include('sports.partials.player')
        <p class="status-note">{{ $player['mode'] === 'hls' ? '畫質依來源提供；低延遲模式較容易受網路波動影響。省流量模式會在來源提供多畫質時降低畫質。切換頁籤仍持續播放；手動暫停或關閉播放頁才會停止載入影音。' : '使用原站播放列控制播放、暫停及音量。' }} 網頁全螢幕可填滿分頁，按 Esc 還原。</p>
        <noscript><div class="notice warning">站內播放器需要 JavaScript。請啟用 JavaScript，或使用原連結。</div></noscript>
    @endif
@endsection
