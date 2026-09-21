@inject('adsense', 'App\Support\AdSense')
@if ($adsense->clientId())
    <meta name="google-adsense-account" content="{{ $adsense->clientId() }}">
    @if ($adsense->enabled() && $showAds)
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ $adsense->clientId() }}" crossorigin="anonymous"></script>
    @endif
@endif
