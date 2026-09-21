@inject('adsense', 'App\Support\AdSense')
@php
    $publicPage = request()->routeIs('index', 'tag')
        || (request()->routeIs('article') && isset($article) && (int) $article->status === 1);
    $slotId = $adsense->enabled() && $publicPage ? $adsense->slotId($placement) : null;
@endphp
@if ($slotId)
    <aside class="manual-ad manual-ad--{{ $placement }}" aria-label="廣告" data-manual-ad v-once>
        <div class="manual-ad-label">廣告</div>
        <ins class="adsbygoogle manual-ad-unit manual-ad-unit--{{ $placement }}"
             data-ad-client="{{ $adsense->clientId() }}"
             data-ad-slot="{{ $slotId }}"></ins>
    </aside>
@endif
