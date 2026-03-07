@extends('layouts/base')

@section('title')
<title>Amanda | 探店 | 美食 | 生活 | 開箱</title>
@endsection

@section('styles')
<link rel="stylesheet" href="{{ asset('js/ckeditor5/ckeditor5.css') }}">
@endsection

@section('content')
    <div class="container">
        <div class="col-12">
            <div class="row">
                <div class="col-md-8 col-12">
                    <template v-if="article.status == 2 && lock">
                        {{-- 輸入密碼 --}}
                        <span class="text-secondary">這篇文章受密碼保護，請輸入密碼：</span>
                        <input type="password" class="form-control w-50" placeholder="請輸入密碼" v-model="password">
                        <button class="btn btn-primary mt-2" @click="verify()">確認</button>
                    </template>
                    <div v-else class="post my-4">
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
                        <div class="article-content ck-content" v-html="article.content"></div>
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
                    lock: true,
                }
            },
            mounted() {
            },
            watch: {
            },
            computed: {
            },
            methods: {
                verify() {
                    if (this.password == this.article.password) {
                        this.lock = false;
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