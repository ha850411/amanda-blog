@extends('admin/layouts/base')

@section('title')
    <title>後台-文章管理</title>
@endsection

@section('content')
    @include('admin/layouts/menu')

    <div class="main">
        @include('admin/layouts/header')
        <div class="contain p-4">
            <nav aria-label="breadcrumb mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.html">首頁</a></li>
                    <li class="breadcrumb-item"><a href="article.html">文章管理</a></li>
                    <li class="breadcrumb-item active" aria-current="page">新增/編輯</li>
                </ol>
            </nav>
            <h4>文章管理-新增/編輯</h4>
            <div class="p-3">
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">標題內容</label>
                    <input type="text" class="form-control" id="exampleFormControlInput1" placeholder="請輸入您的標題內容">
                </div>
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">文章標籤</label>
                    <div class="d-flex justify-content-between">
                        <div>
                            <button type="button" class="btn btn-success btn-md">台中美食<i
                                    class="fa-solid fa-circle-xmark mx-1"></i></button>
                            <button type="button" class="btn btn-success btn-md">牛排館<i
                                    class="fa-solid fa-circle-xmark mx-1"></i></button>
                        </div>
                        <button type="button" class="btn btn-primary btn-md" onclick="editaddbox()">新增</button>
                    </div>
                </div>
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">文章日期</label>
                    <input class="form-control" type="date" placeholder="請選擇日期" id="datePicker">
                </div>
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">文章狀態</label>
                    <select class="form-select mb-3">
                        <option selected>請選擇文章狀態</option>
                        <option value="1">公開</option>
                        <option value="2">密碼</option>
                        <option value="3">隱藏</option>
                    </select>
                </div>
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">密碼設定</label>
                    <input type="text" class="form-control" id="exampleFormControlInput1" placeholder="請輸入您的密碼">
                </div>
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">文章內容</label>
                    <div class="main-container w-100">
                        <div id="editor">
                            <p>請輸入文字</p>
                        </div>
                    </div>
                </div>
                <div class="mt-3 text-center">
                    <button type="button" class="btn btn-secondary" onclick="cancel()">取消</button>
                    <button type="button" class="btn btn-primary" onclick="confirm()">確認</button>
                </div>
            </div>
        </div>
    </div>
    <div class="model edit_add_model">
        <div class="edit_add_box rounded shadow-lg bg-white">
            <div class="rounded-top bg-light text-center p-2">
                <div class="text-secondary fw-bolder">新增</div>
            </div>
            <div class="p-3">
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">文章標籤</label>
                    <input type="text" class="form-control" placeholder="請輸入您的文章標籤">
                    <div class="mt-2">
                        <button type="button" class="btn btn-success btn-sm">台中美食<i
                                class="fa-solid fa-circle-xmark mx-1"></i></button>
                        <button type="button" class="btn btn-success btn-sm">牛排館<i
                                class="fa-solid fa-circle-xmark mx-1"></i></button>
                    </div>
                </div>
                <div class="mt-3 text-center">
                    <button type="button" class="btn btn-secondary" onclick="cancel()">取消</button>
                    <button type="button" class="btn btn-primary" onclick="confirm()">確認</button>
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
                    add() {
                        this.modal('edit_add_model', 'show');
                    },
                    edit(id) {
                        this.modal('edit_add_model', 'show');
                    },
                },
            });
            const vm = app.mount('#app');
        </script>
    @endsection