@inject('adsense', 'App\Support\AdSense')
@if ($adsense->clientId())
    <meta name="google-adsense-account" content="{{ $adsense->clientId() }}">
    @if ($adsense->enabled() && $showAds)
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ $adsense->clientId() }}" crossorigin="anonymous"></script>
        {{-- Explicit responsive sizes follow Google's supported ad-code changes.
             Keep these in the page, rather than an external stylesheet. --}}
        <style>
            .manual-ad { margin: 2rem 0; text-align: center; }
            .manual-ad-label { margin-bottom: .5rem; color: #6c757d; font-size: .7rem; }
            .manual-ad-unit--article_end { display: block; width: 100%; height: 100px; }
            .manual-ad--sidebar, .manual-ad-unit--sidebar { display: none; }
            .manual-ad:has(ins[data-ad-status="unfilled"]) { display: none; }
            @media (min-width: 768px) {
                .manual-ad-unit--article_end { height: 90px; }
            }
            @media (min-width: 992px) {
                .manual-ad--sidebar { display: block; }
                .manual-ad-unit--sidebar { display: block; width: 250px; height: 250px; margin: 0 auto; }
            }
            @media (min-width: 1200px) {
                .manual-ad-unit--sidebar { width: 300px; }
            }
        </style>
        <script defer src="{{ asset('js/adsense.js') }}"></script>
    @endif
@endif
