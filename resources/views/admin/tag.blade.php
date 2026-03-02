@extends('admin/layouts/base')

@section('title')
<title>後台-標籤管理</title>
@endsection

@section('content')
    @include('admin/layouts/menu')

    <div class="main">
        @include('admin/layouts/header')
        <div class="contain p-4">
            <nav aria-label="breadcrumb mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.html">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">標籤管理</li>
                </ol>
            </nav>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-primary btn-md" @click="add()">新增</button>
            </div>

            <h4 class="mt-3">標籤管理</h4>
            <table class="table">
                <thead class="table-dark">
                    <td>主要選單</td>
                    <td>子選單</td>
                    <td>操作</td>
                </thead>
                <tbody>
                    <td>台中美食</td>
                    <td>
                        <button type="button" class="btn btn-success btn-sm me-1">牛排館</button>
                        <button type="button" class="btn btn-success btn-sm me-1">火鍋館</button>
                        <button type="button" class="btn btn-success btn-sm me-1">咖啡店</button>
                        <button type="button" class="btn btn-success btn-sm me-1">早午餐</button>
                    </td>
                    <td><button type="button" class="btn btn-sm btn-secondary" onclick="edit(1)">編輯</button><button
                            type="button" class="btn btn-sm btn-danger" onclick="deletebox()">刪除</button></td>
                </tbody>
                <tbody>
                    <td>宅配商品</td>
                    <td></td>
                    <td><button type="button" class="btn btn-sm btn-secondary" onclick="edit(1)">編輯</button><button
                            type="button" class="btn btn-sm btn-danger" onclick="deletebox()">刪除</button></td>
                </tbody>
                <tbody>
                    <td>采耳體驗</td>
                    <td></td>
                    <td><button type="button" class="btn btn-sm btn-secondary" onclick="edit(1)">編輯</button><button
                            type="button" class="btn btn-sm btn-danger" onclick="deletebox()">刪除</button></td>
                </tbody>
                <tbody>
                    <td>按摩體驗</td>
                    <td></td>
                    <td><button type="button" class="btn btn-sm btn-secondary" onclick="edit(1)">編輯</button><button
                            type="button" class="btn btn-sm btn-danger" onclick="deletebox()">刪除</button></td>
                </tbody>
            </table>
        </div>
    </div>
    <div class="model" ref="delete_model">
        <div class="deletebox rounded shadow-lg bg-white text-center">
            <div class="rounded-top bg-light text-center p-2">
                <div class="text-secondary fw-bolder">刪除</div>
            </div>
            <div class="p-3">
                <div>確認刪除此筆內容?</div>
                <div class="mt-3">
                    <button type="button" class="btn btn-secondary" onclick="cancel()">取消</button>
                    <button type="button" class="btn btn-primary mt-2 mt-md-0" onclick="confirm()">確認</button>
                </div>
            </div>
        </div>
    </div>
    <div class="model" ref="edit_add_model">
        <div class="edit_add_box rounded shadow-lg bg-white">
            <div class="rounded-top bg-light text-center p-2">
                <div class="text-secondary fw-bolder">編輯/新增</div>
            </div>
            <div class="p-3">
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">主要選單</label>
                    <input type="text" class="form-control" id="exampleFormControlInput1" placeholder="請輸入您的主要選單">
                </div>
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">子選單</label>
                    <input type="text" class="form-control" id="exampleFormControlInput1" placeholder="請輸入您的子選單">
                    <div class="mt-2">
                        <button type="button" class="btn btn-success btn-sm me-1">牛排館<i
                                class="fa-solid fa-circle-xmark mx-1"></i></button>
                        <button type="button" class="btn btn-success btn-sm me-1">火鍋館<i
                                class="fa-solid fa-circle-xmark mx-1"></i></button>
                        <button type="button" class="btn btn-success btn-sm me-1">咖啡店<i
                                class="fa-solid fa-circle-xmark mx-1"></i></button>
                        <button type="button" class="btn btn-success btn-sm me-1">早午餐<i
                                class="fa-solid fa-circle-xmark mx-1"></i></button>
                    </div>
                </div>
                <div class="mt-3 text-center">
                    <button type="button" class="btn btn-secondary me-1" @click="modal('edit_add_model', 'hide')">取消</button>
                    <button type="button" class="btn btn-primary" @click="modal('edit_add_model', 'hide')">確認</button>
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