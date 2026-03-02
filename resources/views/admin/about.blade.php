@extends('admin/layouts/base')

@section('title')
<title>後台-個人資料</title>
@endsection

@section('content')
    @include('admin/layouts/menu')

    <div class="main">
        @include('admin/layouts/header')
        <div class="contain p-4">
            <nav aria-label="breadcrumb mb-3">
                <ol class="breadcrumb">
                  <li class="breadcrumb-item"><a href="index.html">首頁</a></li>
                  <li class="breadcrumb-item active" aria-current="page">個人資料</li>
                </ol>
            </nav>
            <div class="d-flex justify-content-end mb-3">
                <button type="button" class="btn btn-primary btn-md" @click="modal('edit_add_model')">編輯</button>
            </div>
            <h4>標題管理</h4>
            <div class="table-responsive">
                <table class="table">
                    <thead class="table-dark">
                        <td>標題內容</td>
                        <td>副標內容</td>
                    </thead>
                    <tbody>
                        <td>我是Amanda</td>
                        <td>美食｜開箱｜生活</td>
                    </tbody>
                </table>
            </div>
            
            <h4 class="mt-3">關於我</h4>
            <table class="table">
                <thead class="table-dark">
                    <td>頭像圖片</td>
                    <td>介紹內容</td>
                </thead>
                <tbody>
                    <td><img src="https://picsum.photos/40/40?random=2"></td>
                    <td>土身土長的台中人，熱愛美食，也喜歡開箱</td>
                </tbody>
            </table>
        </div>
    </div>
    <div class="model" ref="edit_add_model">
        <div class="edit_add_box rounded shadow-lg bg-white">
            <div class="rounded-top bg-light text-center p-2">
                <div class="text-secondary fw-bolder">編輯/新增</div>
            </div>
            <div class="p-3">
                <h5>標題管理</h5>
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">標題內容</label>
                    <input type="text" class="form-control" placeholder="請輸入您的標題內容">
                </div>
                <div class="mt-2">
                    <label for="exampleFormControlTextarea1" class="form-label">副標內容</label>
                    <textarea class="form-control" rows="3" placeholder="請輸入您的副標內容"></textarea>
                </div>
                <h5 class="mt-2">關於我</h5>
                <div class="mt-2">
                    <label for="exampleFormControlTextarea1" class="form-label">頭像圖片</label>
                    <div class="input-group">
                        <input type="file" class="form-control">
                    </div>
                </div>
                <div class="mt-2">
                    <label for="exampleFormControlTextarea1" class="form-label">介紹內容</label>
                    <textarea class="form-control" rows="3" placeholder="請輸入您的介紹內容"></textarea>
                </div>
                <div class="mt-3 text-center">
                    <button type="button" class="btn btn-secondary me-1" @click="modal('edit_add_model', 'hide')">取消</button>
                    <button type="button" class="btn btn-primary" @click="confirm()">確認</button>
                </div>
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
        }
    },
    mounted() {
    },
    watch: {
    },
    computed: {
    },
    methods: {
        confirm() {
            // todo
            this.modal('edit_add_model', 'hide');
        }
    },
});
const vm = app.mount('#app');
</script>
@endsection