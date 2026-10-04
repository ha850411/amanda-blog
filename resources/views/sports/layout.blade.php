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
    <link rel="stylesheet" href="{{ asset('css/sports-player.css') }}?v=client-1">
    <script src="{{ asset('js/sports.js') }}" defer></script>
    @stack('scripts')
</head>
<body>
    <a class="skip-link" href="#main">跳至主要內容</a>
    <header class="site-header">
        <div class="shell header-inner">
            <a class="brand" href="{{ route('sports.index') }}"><span class="brand-mark" aria-hidden="true">E</span> Easonn <strong>SPORTS</strong></a>
        </div>
    </header>
    <main id="main" class="shell">@yield('content')</main>
    <footer class="shell site-footer">
        <p>賽程與轉播連結：<a href="https://sportsurge.pro/" target="_blank" rel="noopener noreferrer">Sportsurge / Streamed</a> · <a href="https://streameast.cool" target="_blank" rel="noopener noreferrer">Streameast</a>。時間皆為台灣時間（UTC+8）。</p>
        <p>來源可能異動或停止提供。影片由瀏覽器直接向第三方來源載入，本站不轉送或儲存影音；原站播放器可能包含廣告。未收錄中文名稱的隊伍保留原名。</p>
    </footer>
</body>
</html>
