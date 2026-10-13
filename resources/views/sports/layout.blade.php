<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', '體育賽事') · Easonn SPORTS</title>
    <link rel="icon" href="{{ asset('images/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('css/sports.css') }}">
    <link rel="stylesheet" href="{{ asset('css/sports-player.css') }}?v=art-2">
    <script src="{{ asset('js/sports.js') }}" defer></script>
    @stack('scripts')
</head>
<body class="@yield('body-class')">
    <a class="skip-link" href="#main">跳至主要內容</a>
    <header class="site-header">
        <div class="shell header-inner">
            <a class="brand" href="{{ route('sports.index') }}"><span class="brand-mark" aria-hidden="true">E</span> Easonn <strong>SPORTS</strong></a>
            <div class="header-nav">
                <a class="back-blog" href="/">← 回部落格</a>
            </div>
        </div>
    </header>
    <main id="main" class="shell">@yield('content')</main>
    <footer class="shell site-footer">
        <p>賽程與轉播連結：<a href="https://sportsurge.pro/" target="_blank" rel="noopener noreferrer">Sportsurge / Streamed</a> · <a href="https://streameast.cool" target="_blank" rel="noopener noreferrer">Streameast</a> · <a href="https://mlbwebcast.com/" target="_blank" rel="noopener noreferrer">MLBWebcast</a>。時間皆為台灣時間（UTC+8）。</p>
        <p>來源可能異動或停止提供。已支援的來源可使用站內播放器，其他來源請開啟原連結。直播畫面可能包含廣告。未收錄中文名稱的隊伍保留原名。</p>
    </footer>
</body>
</html>
