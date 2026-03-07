@extends('layouts/base')

@section('title')
<title>Amanda | 探店 | 美食 | 生活 | 開箱</title>
@endsection

@section('content')
<div class="container">
    <div class="col-12">
        <div class="row">
            <div class="col-md-8 col-12">
                
                <template v-if="!articles.loading && articles.data.length === 0">
                    <div class="d-flex justify-content-center my-5">
                        <p class="text-secondary">目前沒有文章喔！</p>
                    </div>
                </template>

                <template v-if="articles.data.length > 0">
                    <div class="post my-4" v-for="(item, index) in articles.data" :key="index">
                        <div class="title_area">
                            <div class="title d-flex justify-content-between align-items-center">
                                <h4 class="m-0 py-3">@{{ item.title }}</h4>
                                <div class="time py-2 text-secondary">@{{ formatDate(item.created_at) }}</div>
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
                            <form class="row g-3" v-if="item.status == 2 && item.password != item.confirm_password">
                                <div class="col-auto">
                                    <label for="inputPassword2" class="visually-hidden">密碼</label>
                                    <input type="password" class="form-control" placeholder="請輸入您的密碼" v-model="item.temp_pwd" :ref="'pwd_idx_' + index">
                                </div>
                                <div class="col-auto">
                                    <button type="button" class="btn btn-primary mb-3" @click="verify(item, 'pwd_idx_' + index)">確認</button>
                                </div>
                            </form>
                            <template v-else>
                                <img class="w-50" v-if="item.first_image" :src="item.first_image">
                            </template>
                        </div>
                        <div class="more d-flex justify-content-end mt-4">
                            <a :href="getArticleUrl(item.id)">
                                <span class="text-white bg-dark bg-gradient rounded-1 py-2 px-4">閱讀更多<i class="fa-solid fa-angles-right"></i></span></a>
                        </div>
                    </div>
                    {{-- show more --}}
                    <div class="d-flex justify-content-center my-5" v-if="articles.current_page < Math.ceil(articles.total / articles.params.perpage)">
                        <button class="btn btn-primary" @click="articles.params.page++">載入更多</button>
                    </div>
                </template>

                {{-- loading --}}
                <template v-if="articles.loading">
                    <div class="d-flex justify-content-center my-5">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </template>
                {{--                 
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
                --}}
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
            articles: {
                route: '{{ route('api.article.index') }}',
                loading: false,
                data: [],
                params: {
                    page: 1,
                    perpage: 5,
                    show_first_image: 1,
                    tagId: '{{ $tagId }}',
                },
                current_page: 1,
                total: 0,
            },
        }
    },
    mounted() {
        this.getArticles();
    },
    watch: {
        'articles.params.page': {
            handler(newPage) {
                this.getArticles();
            },
        },
    },
    computed: {
    },
    methods: {
        async getArticles() {
            try {
                this.articles.loading = true;
                const res = await axios.get(this.articles.route, {
                    params: this.articles.params
                });
                this.articles.data = [...this.articles.data, ...res.data.data];
                this.articles.current_page = res.data.current_page;
                this.articles.total = res.data.total;
            } catch (error) {
                console.error(error);
            } finally {
                this.articles.loading = false;
            }
        },
        verify(item, ref) {
            if (item.password == item.temp_pwd) {
                item.confirm_password = item.password;
            } else {
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