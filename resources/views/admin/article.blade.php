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
                  <li class="breadcrumb-item active" aria-current="page">文章管理</li>
                </ol>
            </nav>
            <div class="search_list py-1">
                <div class="col-md-12">
                    <div class="row">
                        <div class="col-md-6 mt-md-0 mt-2">
                            <label for="selectName" class="form-label">建立日期</label>
                            <div class="row">
                                <div class="col-md-6">                                
                                    <input class="form-control" type="date" placeholder="請選擇起始日">
                                </div>
                                <div class="col-md-6">
                                    <input class="form-control mt-md-0 mt-2" type="date" placeholder="請選擇結束日">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mt-md-0 mt-2">
                            <label for="selectState" class="form-label">文章狀態</label>
                            <select class="form-select">
                                <option value="1" selected="">公開</option>
                                <option value="2">密碼</option>
                                <option value="3">隱藏</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="orderNumber" class="form-label">文章標籤</label>
                            <input class="form-control" type="text" placeholder="請輸入文章標籤">
                        </div>
                        <div class="col-md-3 mt-2 d-block align-content-end">
                            <button type="button" class="btn btn-primary">搜尋</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end">
                <a class="btn btn-primary btn-md" href="{{ route('admin.article.add') }}">新增</a>
            </div>
            <h4>文章管理</h4>
            <div class="table-responsive">
                <table class="table">
                    <thead class="table-dark">
                        <td>文章日期</td>
                        <td>標題內容</td>
                        <td>文章標籤</td>
                        <td>文章狀態</td>
                        <td>操作</td>
                    </thead>
                    <tbody>
                        <td>2026/02/22</td>
                        <td>LS樂食糖 | 百變多種口味的牛軋糖</td>
                        <td>
                            <button type="button" class="btn btn-success btn-sm">宅配美食</button>
                            <button type="button" class="btn btn-success btn-sm">糖果甜點</button>
                        </td>
                        <td>公開</td>
                        <td><a class="btn btn-sm btn-secondary" href="article_add_edit.html">編輯</a><button type="button" class="btn btn-sm btn-danger" onclick="deletebox()">刪除</button></td>
                    </tbody>
                    <tbody>
                        <td>2026/01/22</td>
                        <td>貴族世家Mini | CP值超高的平價牛排吃到飽</td>
                        <td>                            
                            <button type="button" class="btn btn-success btn-sm">台中美食</button>
                            <button type="button" class="btn btn-success btn-sm">牛排館</button>
                        </td>
                        <td>密碼</td>
                        <td><a class="btn btn-sm btn-secondary" href="article_add_edit.html">編輯</a><button type="button" class="btn btn-sm btn-danger" onclick="deletebox()">刪除</button></td>
                    </tbody>
                    <tbody>
                        <td>2026/02/22</td>
                        <td>LS樂食糖 | 百變多種口味的牛軋糖</td>
                        <td>
                            <button type="button" class="btn btn-success btn-sm">宅配美食</button>
                            <button type="button" class="btn btn-success btn-sm">糖果甜點</button>
                        </td>
                        <td>隱藏</td>
                        <td><a class="btn btn-sm btn-secondary" href="article_add_edit.html">編輯</a><button type="button" class="btn btn-sm btn-danger" onclick="deletebox()">刪除</button></td>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    {{-- modal --}}
    <div class="model delete_model">
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