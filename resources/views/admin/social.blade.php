@extends('admin/layouts/base')

@section('title')
<title>後台-社群管理</title>
@endsection

@section('content')
    @include('admin/layouts/menu')

    <div class="main">
        @include('admin/layouts/header')
        <div class="contain p-4">
            <nav aria-label="breadcrumb mb-3">
                <ol class="breadcrumb">
                  <li class="breadcrumb-item"><a href="index.html">首頁</a></li>
                  <li class="breadcrumb-item active" aria-current="page">社群管理</li>
                </ol>
            </nav>

            <h4 class="mt-3">社群管理</h4>
            <table class="table">
                <thead class="table-dark">
                    <td>社群圖片</td>
                    <td>網址連結</td>
                    <td>狀態</td>
                    <td>操作</td>
                </thead>
                <tbody>
                    <td><i class="fa-brands fa-facebook fa-2x"></i></td>
                    <td>https://www.facebook.com/</td>
                    <td>隱藏</td>
                    <td><button type="button" class="btn btn-sm btn-secondary" onclick="editaddbox()">編輯</button></td>
                </tbody>
                <tbody>
                    <td><i class="fa-brands fa-instagram fa-2x"></i></td>
                    <td>https://www.instagram.com/</td>
                    <td>公開</td>
                    <td><button type="button" class="btn btn-sm btn-secondary" onclick="editaddbox()">編輯</button></td>
                </tbody>
                <tbody>
                    <td><i class="fa-brands fa-youtube fa-2x"></i></td>
                    <td>https://www.youtube.com/</td>
                    <td>公開</td>
                    <td><button type="button" class="btn btn-sm btn-secondary" onclick="editaddbox()">編輯</button></td>
                </tbody>
            </table>
        </div>
    </div>
    <div class="model edit_add_model">
        <div class="edit_add_box rounded shadow-lg bg-white">
            <div class="rounded-top bg-light text-center p-2">
                <div class="text-secondary fw-bolder">編輯/新增</div>
            </div>
            <div class="p-3">
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">社群圖片</label>
                    <div>
                        <i class="fa-brands fa-instagram fa-2x"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">網址連結</label>
                    <input type="text" class="form-control" id="exampleFormControlInput1" placeholder="請輸入您的網址連結">    
                </div>
                <div class="mt-2">
                    <label for="selectState" class="form-label">狀態</label>
                    <select class="form-select">
                        <option value="1" selected="">公開</option>
                        <option value="2">隱藏</option>
                    </select>
                </div>
                <div class="mt-3 text-center">
                    <button type="button" class="btn btn-secondary" onclick="cancel()">取消</button>
                    <button type="button" class="btn btn-primary" onclick="confirm()">確認</button>
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