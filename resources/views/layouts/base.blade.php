<!DOCTYPE html>
<html lang="zh-TW">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @hasSection('title')
        @yield('title')
    @else
        <title>Amanda | 探店 | 美食 | 生活 | 開箱</title>
    @endif
    <link rel="canonical" href="{{ $canonicalUrl ?? url()->current() }}" />
    <meta name="robots" content="{{ $robots ?? 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1' }}">
    <meta name="author" content="Amanda">
    <link rel="alternate" type="application/rss+xml" title="Amanda's Blog RSS Feed" href="{{ url('/rss.xml') }}" />
    <link rel="llms-txt" type="text/markdown" title="LLMs Summary Index" href="{{ url('/llms.txt') }}" />
    @yield('meta')
    @include('layouts.adsense', [
        'showAds' => request()->routeIs('index', 'tag')
            || (request()->routeIs('article') && isset($article) && (int) $article->status === 1),
    ])
    <link rel="icon" href="{{ asset('images/favicon.png') }}" type="image/png">
    <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fontawesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
    @yield('styles')

    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','GTM-WP6N5NTS');</script>
    <!-- End Google Tag Manager -->
</head>

<body>
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-WP6N5NTS"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->

    @hasSection('static_content')
        <header class="container py-4 border-bottom">
            <a href="{{ route('index') }}" class="h4 text-dark text-decoration-none">Amanda | 探店 | 美食 | 生活 | 開箱</a>
        </header>
        @yield('static_content')
    @else
    {{-- header --}}
    <div id="app">
        @include('layouts/header')
        @yield('content')
    </div>

    <script src="{{ asset('js/vue/vue.global.min.js') }}"></script>
    <script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('js/axios/axios.min.js') }}"></script>
    <script src="{{ asset('js/sweetalert2/sweetalert2.min.js') }}"></script>
    <script>
        const baseMixin = {
            data() {
                return {
                    base: {
                        isMenuOpen: false,
                        visit: {
                            route: '{{ route('api.visit.index') }}',
                            store: '{{ route('api.visit.store') }}',
                            data: null
                        },
                        web: {
                            tag: '{{ route("tag", ["tagId" => "__TAG_ID__"]) }}',
                        },
                        article_verify_route: '{{ route("api.article.verify", ["id" => "__ARTICLE_ID__"]) }}',
                        detail_route: '{{ route("article", ["id" => "__ARTICLE_ID__"]) }}',
                    }
                }
            },
            watch: {
            },
            mounted() {
                this.getVisit();
                this.addVisit();
                this.$nextTick(() => {
                    document.dispatchEvent(new Event('amanda:content-ready'));
                });
            },
            methods: {
                toggleMenu() {
                    this.base.isMenuOpen = !this.base.isMenuOpen;
                },
                async getVisit() {
                    try {
                        const res = await axios.get(this.base.visit.route);
                        this.base.visit.data = res.data.data;
                    } catch (error) {
                        console.error(error);
                    }
                },
                async addVisit() {
                    try {
                        await axios.post(this.base.visit.store);
                    } catch (error) {
                        console.error(error);
                    }
                },
                getTagUrl(tagId) {
                    return this.base.web.tag.replace('__TAG_ID__', encodeURIComponent(String(tagId)));
                },
                getArticleUrl(id) {
                    return this.base.detail_route.replace('__ARTICLE_ID__', encodeURIComponent(String(id)));
                },
                getArticleVerifyUrl(id) {
                    return this.base.article_verify_route.replace('__ARTICLE_ID__', encodeURIComponent(String(id)));
                },
                formatDate(dateString) {
                    return new Date(dateString).toLocaleDateString('zh-TW', {
                        year: 'numeric',
                        month: 'long',
                        day: '2-digit',
                    });
                },
                formatNumber(value) {
                    return Number(value || 0).toLocaleString('zh-TW');
                },
                async verifyArticlePassword(articleId, password) {
                    const res = await axios.post(this.getArticleVerifyUrl(articleId), {
                        password,
                    });

                    return res.data.data;
                },
            }
        };
    </script>
    @yield('scripts')
    @endif
    @include('layouts.footer')
</body>

</html>
