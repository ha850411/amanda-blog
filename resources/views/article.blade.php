@extends('layouts/base')

@section('title')
<title>{{ $article->title }} - Amanda | 探店 | 美食 | 生活 | 開箱</title>
@endsection

@section('meta')
<meta name="description" content="{{ $description }}">
<meta property="og:title" content="{{ $article->title }} - Amanda | 探店 | 美食 | 生活 | 開箱">
<meta property="og:description" content="{{ $description }}">
<meta property="og:type" content="article">
<meta property="og:url" content="{{ $articleUrl }}">
<link rel="alternate" type="text/markdown" href="{{ route('article.markdown', ['id' => $article->id]) }}" title="Markdown Version" />
@if($articleImage)
<meta property="og:image" content="{{ $articleImage }}">
@endif
<meta property="og:site_name" content="Amanda">
<meta name="twitter:card" content="{{ $articleImage ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $article->title }} - Amanda | 探店 | 美食 | 生活 | 開箱">
<meta name="twitter:description" content="{{ $description }}">
@if($articleImage)
<meta name="twitter:image" content="{{ $articleImage }}">
@endif
@if ((int) $article->status === 1)
<script type="application/ld+json">{!! json_encode($articleJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
@endsection

@section('styles')
<link rel="stylesheet" href="{{ asset('js/ckeditor5/ckeditor5.css') }}">
@endsection

@section('content')
<main class="container">
    <div class="col-12">
        <div class="row">
            <div class="col-md-8 col-12">
                <article class="post my-4">
                    <header class="title_area">
                        <time class="time text-secondary" datetime="{{ $article->created_at?->toIso8601String() }}" v-pre>{{ $article->created_at?->format('Y年n月d日') }}</time>
                        <div class="title">
                            <h1 class="h4 m-0 py-2" v-pre>{{ $article->title }}</h1>
                        </div>
                        @if ($article->tags->isNotEmpty())
                            <div class="tag py-2 mb-4" v-pre>
                                @foreach ($article->tags as $tag)
                                    <a href="{{ route('tag', ['tagId' => $tag->id]) }}" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1">
                                        <i class="fa-solid fa-tag"></i>{{ $tag->name }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </header>
                    @if ((int) $article->status === 2 && ! $isPasswordVerified)
                        <div v-if="lock">
                            <label for="article-password" class="text-secondary">這篇文章受密碼保護，請輸入密碼：</label>
                            <input id="article-password" type="password" class="form-control w-50" placeholder="請輸入密碼" v-model="password" @keyup.enter="verify()">
                            <button class="btn btn-primary mt-2" @click="verify()">確認</button>
                            <noscript><p>請啟用 JavaScript 後驗證文章密碼。</p></noscript>
                        </div>
                        <div v-else class="article-content ck-content" v-html="article.content"></div>
                    @else
                        <div class="article-content ck-content" v-pre>{!! $article->content !!}</div>
                    @endif
                </article>
                @include('layouts.ad-unit', ['placement' => 'article_end'])
            </div>
            @include('layouts/about')
        </div>
    </div>
</main>
@endsection

@section('scripts')
    <script>
        const app = Vue.createApp({
            mixins: [baseMixin],
            data() {
                return {
                    article: @json($frontendArticle),
                    password: '',
                    lock: Number(@json($article->status)) === 2 && !@json($isPasswordVerified),
                }
            },
            mounted() {
                this.lock = Number(this.article.status) === 2 && !this.article.is_password_verified;
            },
            watch: {
            },
            computed: {
            },
            methods: {
                async verify() {
                    try {
                        const verifiedArticle = await this.verifyArticlePassword(this.article.id, this.password);
                        Object.assign(this.article, verifiedArticle);
                        this.lock = !this.article.is_password_verified;
                        this.password = '';
                    } catch (error) {
                        Swal.fire({
                            icon: 'error',
                            title: '密碼錯誤',
                            text: '您輸入的密碼不正確，請再試一次。',
                        });
                    }
                }
            },
        });
        const vm = app.mount('#app');
    </script>
@endsection
