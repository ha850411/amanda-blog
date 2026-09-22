<div class="col-md-4 col-12">
    <div class="about d-flex flex-column align-items-center" v-pre>
        <h5 class="text-white bg-dark bg-gradient rounded-1 title text-center p-2 my-4 w-100">關於我</h5>
        @if ($siteAbout?->picture)
            <div class="avatar rounded-circle overflow-hidden w-50 m-3">
                <img src="{{ $siteAbout->picture }}" alt="Amanda" class="w-100">
            </div>
        @endif
        <p style="white-space: pre-line">{{ $siteAbout?->description ?? '' }}</p>
    </div>
    @include('layouts.ad-unit', ['placement' => 'sidebar'])
    <div class="new_article text-left" v-pre>
        <h5 class="text-white bg-dark bg-gradient rounded-1 title text-center p-2 my-4">最新文章</h5>
        @foreach ($latestArticles as $latestArticle)
            <a href="{{ route('article', ['id' => $latestArticle->id]) }}" class="text-dark">
                <h6 class="py-1 px-4 m-0">
                    <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        @if ((int) $latestArticle->status === 2)<i class="fa-solid fa-key"></i>@endif
                        <span>{{ $latestArticle->title }}</span>
                    </div>
                </h6>
            </a>
        @endforeach
    </div>
    <div class="d-md-block d-none category" v-pre>
        <h5 class="text-white bg-dark bg-gradient rounded-1 title text-center p-2 my-4">文章分類</h5>
        @foreach ($siteTags as $tag)
            <div class="menu">
                <a href="{{ route('tag', ['tagId' => $tag->id]) }}" class="menu text-dark">
                    <h6 class="py-1 px-4 m-0"><i class="fa-solid fa-arrows-to-dot"></i>{{ $tag->name }}</h6>
                </a>
                @if ($tag->children->isNotEmpty())
                    <div class="list-unstyled mt-2">
                        @foreach ($tag->children as $child)
                            <a href="{{ route('tag', ['tagId' => $child->id]) }}" class="d-block text-secondary py-1 px-5">{{ $child->name }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    <div class="number text-center">
        <h5 class="text-white bg-dark bg-gradient rounded-1 title p-2 my-4">網站瀏覽</h5>
        <div class="date">今日瀏覽數：<span v-text="formatNumber(base.visit.data?.today)">—</span></div>
        <div class="total">總瀏覽數：<span v-text="formatNumber(base.visit.data?.total)">—</span></div>
    </div>
</div>
