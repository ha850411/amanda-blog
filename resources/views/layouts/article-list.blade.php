@forelse ($initialArticles as $item)
    <article class="post my-4" v-pre>
        <header class="title_area">
            <time class="time text-secondary" datetime="{{ \Carbon\Carbon::parse($item['updated_at'])->toIso8601String() }}">{{ \Carbon\Carbon::parse($item['updated_at'])->format('Y年n月d日') }}</time>
            <div class="title">
                <h2 class="h4 m-0 py-2"><a href="{{ route('article', ['id' => $item['id']]) }}" class="text-dark">{{ $item['title'] }}</a></h2>
            </div>
            @if ($item['tags'])
                <div class="tag py-2 mb-4">
                    @foreach ($item['tags'] as $tag)
                        <a href="{{ route('tag', ['tagId' => $tag['id']]) }}" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1"><i class="fa-solid fa-tag"></i>{{ $tag['name'] }}</a>
                    @endforeach
                </div>
            @endif
        </header>
        <div class="article_area">
            @if ((int) $item['status'] === 2 && ! $item['is_password_verified'])
                <p class="text-secondary">這篇文章受密碼保護。</p>
            @elseif ($item['first_image'])
                <img class="w-50" src="{{ $item['first_image'] }}" alt="{{ $item['title'] }}" loading="lazy">
            @endif
        </div>
        <div class="more d-flex justify-content-end mt-4">
            <a href="{{ route('article', ['id' => $item['id']]) }}"><span class="text-white bg-dark bg-gradient rounded-1 py-2 px-4">閱讀更多<i class="fa-solid fa-angles-right"></i></span></a>
        </div>
    </article>
@empty
    <div class="d-flex justify-content-center my-5"><p class="text-secondary">目前沒有文章喔！</p></div>
@endforelse
