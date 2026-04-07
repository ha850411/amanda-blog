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
@if ((int) $article->status === 2)
<meta name="robots" content="noindex,nofollow">
@endif
@if ((int) $article->status === 1)
<script type="application/ld+json">{!! json_encode($articleJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endif
@endsection

@section('styles')
<link rel="stylesheet" href="{{ asset('js/ckeditor5/ckeditor5.css') }}">
@endsection

@section('ssr_content')
    <div class="container">
        <div class="col-12">
            <div class="row">
                <div class="col-md-8 col-12">
                    <div class="post my-4">
                        <div class="title_area">
                            <div class="title d-flex justify-content-between align-items-center">
                                <h1 class="h4 m-0 py-3">{{ $article->title }}</h1>
                                <div class="time py-2 text-secondary">{{ $article->created_at?->format('Y/m/d') }}</div>
                            </div>
                            @if ($article->tags && $article->tags->count() > 0)
                                <div class="tag py-2 mb-4">
                                    @foreach ($article->tags as $tag)
                                        <a href="{{ route('tag', ['tagId' => $tag->id]) }}" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1">
                                            <i class="fa-solid fa-tag"></i>{{ $tag->name }}
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @if ($article->status == 2)
                            <span class="text-secondary">這篇文章受密碼保護，請等待頁面載入後輸入密碼。</span>
                        @else
                            <div class="article-content ck-content">{!! $article->content !!}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('content')
    <div class="container">
        <div class="col-12">
            <div class="row">
                <div class="col-md-8 col-12">
                    
                    <div class="post my-4">
                        <div class="title_area">
                            <div class="title d-flex justify-content-between align-items-center">
                                <h4 class="m-0 py-3">@{{ article.title }}</h4>
                                <div class="time py-2 text-secondary">@{{ formatDate(article.created_at) }}</div>
                            </div>
                            <div class="tag py-2 mb-4" v-if="article.tags && article.tags.length > 0">
                                <template v-for="(tag, tagIndex) in article.tags" :key="tagIndex">
                                    <a :href="getTagUrl(tag.id)" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1">
                                        <i class="fa-solid fa-tag"></i>@{{ tag.name }}
                                    </a>
                                </template>
                            </div>
                        </div>
                        
                        {{-- 內容 --}}
                        <template v-if="article.status == 2 && lock">
                            {{-- 輸入密碼 --}}
                            <span class="text-secondary">這篇文章受密碼保護，請輸入密碼：</span>
                            <input type="password" class="form-control w-50" placeholder="請輸入密碼" v-model="password" @keyup.enter="verify()">
                            <button class="btn btn-primary mt-2" @click="verify()">確認</button>
                        </template>
                        <div v-else class="article-content ck-content" v-html="article.content"></div>
                    </div>
                </div>
                {{-- 關於我、最新文章、文章分類、網站瀏覽 --}}
                @include('layouts/about')
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        const app = Vue.createApp({
            mixins: [baseMixin],
            data() {
                return {
                    article: @json($article),
                    password: '',
                    lock: Number(@json($article->status)) === 2,
                }
            },
            mounted() {
                this.syncVerifiedArticleState(this.article);
                this.lock = !this.isArticlePasswordVerified(this.article);
            },
            watch: {
            },
            computed: {
            },
            methods: {
                verify() {
                    if (this.password == this.article.password) {
                        this.lock = false;
                        this.rememberVerifiedArticlePassword(this.article);
                    } else {
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
