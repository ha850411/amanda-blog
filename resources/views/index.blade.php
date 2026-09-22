@extends('layouts/base')

@section('title')
<title>{{ ($selectedTag ? $selectedTag->name . ' - ' : '') . ($articles->currentPage() > 1 ? '第 ' . $articles->currentPage() . ' 頁 - ' : '') . 'Amanda | 探店 | 美食 | 生活 | 開箱' }}</title>
@endsection

@section('meta')
<meta name="description" content="{{ $selectedTag ? 'Amanda 的「' . $selectedTag->name . '」文章整理與分享。' : 'Amanda的探店、美食、生活與開箱紀錄' }}">
<meta property="og:title" content="{{ ($selectedTag ? $selectedTag->name . ' - ' : '') . ($articles->currentPage() > 1 ? '第 ' . $articles->currentPage() . ' 頁 - ' : '') . 'Amanda | 探店 | 美食 | 生活 | 開箱' }}">
<meta property="og:description" content="{{ $selectedTag ? 'Amanda 的「' . $selectedTag->name . '」文章整理與分享。' : 'Amanda的探店、美食、生活與開箱紀錄' }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="og:site_name" content="Amanda">
@if (isset($siteJsonLd))
<script type="application/ld+json">{!! json_encode($siteJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
@endsection

@section('content')
<main class="container">
    <div class="col-12">
        <div class="row">
            <div class="col-md-8 col-12">
                <h1 class="visually-hidden" v-pre>{{ $selectedTag ? $selectedTag->name . ' - 文章列表' : 'Amanda 的探店、美食、生活與開箱紀錄' }}</h1>
                <div v-if="!articles.enhanced">
                    @include('layouts.article-list')
                </div>
                <template v-else>

                
                <template v-if="!articles.loading && articles.data.length === 0">
                    <div class="d-flex justify-content-center my-5">
                        <p class="text-secondary">目前沒有文章喔！</p>
                    </div>
                </template>

                <template v-if="articles.data.length > 0">
                    <div class="post my-4" v-for="(item, index) in articles.data" :key="item.id">
                        <div class="title_area">
                            <div class="time text-secondary">@{{ formatDate(item.updated_at) }}</div>
                            <div class="title">
                                <h2 class="h4 m-0 py-2"><a :href="getArticleUrl(item.id)" class="text-dark">@{{ item.title }}</a></h2>
                            </div>
                            <div class="tag py-2 mb-4" v-if="item.tags && item.tags.length > 0">
                                <template v-for="(tag, tagIndex) in item.tags" :key="tagIndex">
                                    <a :href="getTagUrl(tag.id)" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1">
                                        <i class="fa-solid fa-tag"></i>@{{ tag.name }}
                                    </a>
                                </template>
                            </div>
                        </div>
                        <div class="article_area">
                            <div class="row g-3" v-if="item.status == 2 && !item.is_password_verified">
                                <div class="col-auto">
                                    <label for="inputPassword2" class="visually-hidden">密碼</label>
                                    <input type="password" class="form-control" placeholder="請輸入您的密碼" v-model="item.temp_pwd" :ref="'pwd_idx_' + index" @keyup.enter="verify(item, 'pwd_idx_' + index)">
                                </div>
                                <div class="col-auto">
                                    <button type="button" class="btn btn-primary mb-3" @click="verify(item, 'pwd_idx_' + index)">確認</button>
                                </div>
                            </div>
                            <template v-else>
                                <img class="w-50" v-if="item.first_image" :src="item.first_image" :alt="item.title" loading="lazy">
                            </template>
                        </div>
                        <div class="more d-flex justify-content-end mt-4">
                            <a :href="getArticleUrl(item.id)">
                                <span class="text-white bg-dark bg-gradient rounded-1 py-2 px-4">閱讀更多<i class="fa-solid fa-angles-right"></i></span></a>
                        </div>
                    </div>
                </template>

                </template>
                <nav aria-label="文章分頁" v-if="!articles.autoLoad || articles.failed" class="d-flex justify-content-between my-4">
                    @if ($articles->previousPageUrl())
                        <a href="{{ $articles->currentPage() === 2 ? url()->current() : $articles->previousPageUrl() }}" class="btn btn-outline-dark">上一頁</a>
                    @endif
                    @if ($articles->hasMorePages())
                        <a href="{{ $articles->nextPageUrl() }}" v-bind="{ href: nextPageUrl }" class="btn btn-outline-dark">下一頁</a>
                    @endif
                </nav>

                {{-- scroll sentinel for infinite scroll --}}
                <div ref="scrollSentinel" style="height: 1px;"></div>

                {{-- loading --}}
                <template v-if="articles.loading">
                    <div class="d-flex justify-content-center my-5">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </template>
            </div>
            {{-- 關於我、最新文章、文章分類、網站瀏覽 --}}
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
            articles: {
                route: '{{ route('api.article.index') }}',
                loading: false,
                data: @json($initialArticles).map(item => ({ ...item, temp_pwd: '' })),
                enhanced: false,
                autoLoad: false,
                failed: false,
                params: {
                    page: {{ $articles->currentPage() }},
                    perpage: 5,
                    show_first_image: 1,
                    status: [1, 2],
                    tagId: '{{ $tagId ?? null }}',
                },
                current_page: {{ $articles->currentPage() }},
                total: {{ $articles->total() }},
            },
        }
    },
    mounted() {
        this.articles.enhanced = true;
        this.$nextTick(() => this.initScrollObserver());
    },
    beforeUnmount() {
        if (this.scrollObserver) {
            this.scrollObserver.disconnect();
        }
    },
    computed: {
        nextPageUrl() {
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('page', this.articles.current_page + 1);
            return url.href;
        },
        hasMorePages() {
            return this.articles.current_page < Math.ceil(this.articles.total / this.articles.params.perpage);
        },
    },
    methods: {
        async getArticles() {
            if (this.articles.loading || this.articles.failed || !this.hasMorePages) return;
            try {
                this.articles.loading = true;
                const nextPage = this.articles.current_page + 1;
                const res = await axios.get(this.articles.route, {
                    params: { ...this.articles.params, page: nextPage },
                    timeout: 15000
                });
                if (!Array.isArray(res.data.data) || res.data.current_page !== nextPage) throw new Error('Invalid article page');
                const nextArticles = res.data.data.map(item => ({
                    ...item,
                    temp_pwd: '',
                }));
                const existingIds = new Set(this.articles.data.map(item => item.id));
                this.articles.data.push(...nextArticles.filter(item => !existingIds.has(item.id)));
                this.articles.current_page = res.data.current_page;
                this.articles.total = res.data.total;
            } catch (error) {
                this.articles.failed = true;
                this.scrollObserver?.disconnect();
                console.error(error);
            } finally {
                this.articles.loading = false;
                this.$nextTick(() => this.checkSentinelVisible());
            }
        },
        initScrollObserver() {
            if (!('IntersectionObserver' in window)) return;
            this.articles.autoLoad = true;
            this.scrollObserver = new IntersectionObserver((entries) => {
                const entry = entries[0];
                if (entry.isIntersecting && !this.articles.loading && this.hasMorePages) {
                    this.getArticles();
                }
            }, { rootMargin: '200px' });
            if (this.$refs.scrollSentinel) {
                this.scrollObserver.observe(this.$refs.scrollSentinel);
            }
        },
        checkSentinelVisible() {
            if (!this.articles.autoLoad || this.articles.failed || !this.$refs.scrollSentinel || !this.hasMorePages || this.articles.loading) return;
            const rect = this.$refs.scrollSentinel.getBoundingClientRect();
            if (rect.top <= window.innerHeight + 200) {
                this.getArticles();
            }
        },
        async verify(item, ref) {
            try {
                const verifiedArticle = await this.verifyArticlePassword(item.id, item.temp_pwd);
                Object.assign(item, verifiedArticle, {
                    temp_pwd: '',
                });
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: '密碼錯誤',
                    text: '請重新輸入密碼',
                    timer: 1000,
                    showConfirmButton: false,
                }).then(() => {
                    item.temp_pwd = '';
                });
            }
        }
    },
});
const vm = app.mount('#app');
</script>
@endsection
