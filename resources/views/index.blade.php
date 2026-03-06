@extends('layouts/base')

@section('title')
<title>Amanda | 探店 | 美食 | 生活 | 開箱</title>
@endsection

@section('content')
<div class="container">
    <div class="col-12">
        <div class="row">
            <div class="col-md-8 col-12">
                <div class="post my-4">
                    <div class="title_area">
                        <div class="title d-flex justify-content-between align-items-center">
                            <h4 class="m-0 py-3">LS樂食糖 | 百變多種口味的牛軋糖</h4>
                            <div class="time py-2 text-secondary">Jan 19 2026</div>
                        </div>
                        <div class="tag py-2 mb-4">
                            <a href="#" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1"><i class="fa-solid fa-tag"></i>宅配美食</a>
                            <a href="#" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1"><i class="fa-solid fa-tag"></i>糖果甜點</a>
                        </div>
                    </div>
                    <div class="article_area">
                        <img class="w-100" src="https://picsum.photos/500/400?random=1">
                    </div>
                    <div class="more d-flex justify-content-end mt-4">
                        <a href="{{ route('article', 1) }}"><span class="text-white bg-dark bg-gradient rounded-1 py-2 px-4">閱讀更多<i class="fa-solid fa-angles-right"></i></span></a>
                    </div>
                </div>
                <div class="post my-4">
                    <div class="title_area">
                        <div class="title d-flex justify-content-between align-items-center">
                            <h4 class="m-0 py-3">                                
                                貴族世家Mini | CP值超高的平價牛排吃到飽
                            </h4>
                            <div>
                                <span class="time py-2 text-secondary">Feb 02 2026</span>
                                <span class="badge bg-danger">隱藏</span>
                            </div>
                        </div>
                        <div class="tag py-2 mb-4">
                            <a href="#" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1"><i class="fa-solid fa-tag"></i>台中美食</a>
                            <a href="#" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1"><i class="fa-solid fa-tag"></i>大里區</a>
                            <a href="#" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1"><i class="fa-solid fa-tag"></i>牛排館</a>
                        </div>
                        
                    </div>
                    <div class="article_area">
                        <img class="w-100" src="https://picsum.photos/500/400?random=2">
                    </div>
                    <a href="{{ route('article', 2) }}" class="more d-flex justify-content-end mt-4">
                        <span class="text-white bg-dark bg-gradient rounded-1 py-2 px-4">閱讀更多<i class="fa-solid fa-angles-right"></i></span>
                    </a>
                </div>
                <div class="post my-4">
                    <div class="title_area">
                        <div class="title d-flex justify-content-between align-items-center">
                            <h4 class="m-0 py-3"><i class="fa-solid fa-key"></i>東光灶咖 ｜用料實在的巴斯克蛋糕</h4>
                            <div class="time py-2 text-secondary">Feb 02 2026</div>
                        </div>
                        <div class="tag py-2 mb-4">
                            <a href="#" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1"><i class="fa-solid fa-tag"></i>台中美食</a>
                            <a href="#" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1"><i class="fa-solid fa-tag"></i>大里區</a>
                            <a href="#" class="bg-secondary bg-gradient rounded-1 text-white p-2 me-1"><i class="fa-solid fa-tag"></i>牛排館</a>
                        </div>
                        
                    </div>
                    <div class="article_area">
                        <form class="row g-3">
                            <div class="col-auto">
                                <label for="inputPassword2" class="visually-hidden">密碼</label>
                                <input type="password" class="form-control" id="inputPassword2" placeholder="請輸入您的密碼">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary mb-3">確認</button>
                            </div>
                        </form>
                    </div>
                    <a href="{{ route('article', 3) }}" class="more d-flex justify-content-end mt-4">
                        <span class="text-white bg-dark bg-gradient rounded-1 py-2 px-4">閱讀更多<i class="fa-solid fa-angles-right"></i></span>
                    </a>
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
            api: {
                articles: {
                    route: '{{ route('api.article.index') }}',
                    loading: false,
                    data: [],
                },
            }
        }
    },
    mounted() {
        this.getArticles()
    },
    watch: {
    },
    computed: {
    },
    methods: {
        async getArticles() {
            try {
                this.api.articles.loading = true;
                const res = await axios.get(this.api.articles.route);
                this.api.articles.data = res.data.data;
            } catch (error) {
                console.error(error);
            } finally {
                this.api.articles.loading = false;
            }
        }
    },
});
const vm = app.mount('#app');
</script>
@endsection