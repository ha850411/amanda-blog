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
                                    <input class="form-control" type="date" placeholder="請選擇起始日" v-model="condition.start">
                                </div>
                                <div class="col-md-6">
                                    <input class="form-control mt-md-0 mt-2" type="date" placeholder="請選擇結束日" v-model="condition.end">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mt-md-0 mt-2">
                            <label for="selectState" class="form-label">文章狀態</label>
                            <select class="form-select" v-model="condition.status">
                                <option value="" selected="">全部</option>
                                <option value="1">公開</option>
                                <option value="2">密碼</option>
                                <option value="3">隱藏</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="orderNumber" class="form-label">文章標籤</label>
                            <input class="form-control" type="text" placeholder="請輸入文章標籤" v-model="condition.tag">
                        </div>
                        <div class="col-md-3 mt-2 d-block align-content-end">
                            <button type="button" class="btn btn-primary" @click="getList">搜尋</button>
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
                        <tr>
                            <td>日期</td>
                            <td>標題</td>
                            <td>標籤</td>
                            <td>狀態</td>
                            <td>操作</td>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="5" class="text-center">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-else v-for="item in list" :key="item.id">
                            <td>@{{ formatDate(item.created_at) }}</td>
                            <td>@{{ item.title }}</td>
                            <td>
                                <button v-for="tag in item.tags" :key="tag.id" type="button" class="btn btn-success btn-sm me-1">@{{ tag.name }}</button>
                            </td>
                            <td>
                                <span v-if="item.status == 1">公開</span>
                                <span v-else-if="item.status == 2">密碼</span>
                                <span v-else-if="item.status == 3">隱藏</span>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-secondary me-1" @click="edit(item.id)">編輯</a>
                                <button type="button" class="btn btn-sm btn-danger" @click="deletebox(item.id)">刪除</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 分頁區塊 -->
            <div class="d-flex justify-content-end mt-4" v-if="totalPage > 0">
                <nav aria-label="Page navigation">
                    <ul class="pagination">
                        <!-- 第一頁 -->
                        <li class="page-item" :class="{ disabled: page === 1 }">
                            <a class="page-link" href="#" @click.prevent="changePage(1)">第一頁</a>
                        </li>
                        
                        <!-- 上一頁 -->
                        <li class="page-item" :class="{ disabled: page === 1 }">
                            <a class="page-link" href="#" @click.prevent="changePage(page - 1)">上一頁</a>
                        </li>
                        
                        <!-- 下拉式選單選擇頁數 -->
                        <li class="page-item d-flex align-items-center mx-2">
                            <select class="form-select form-select-sm" v-model="page" @change="getList()">
                                <option v-for="p in totalPage" :key="p" :value="p">第 @{{ p }} 頁</option>
                            </select>
                        </li>

                        <!-- 下一頁 -->
                        <li class="page-item" :class="{ disabled: page === totalPage }">
                            <a class="page-link" href="#" @click.prevent="changePage(page + 1)">下一頁</a>
                        </li>
                        
                        <!-- 最後一頁 -->
                        <li class="page-item" :class="{ disabled: page === totalPage }">
                            <a class="page-link" href="#" @click.prevent="changePage(totalPage)">最後一頁</a>
                        </li>
                    </ul>
                </nav>
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
                    route: {
                        list: '{{ route('api.admin.article.index') }}',
                        edit: '{{ route('admin.article.edit', ':id') }}',
                    },
                    condition: {
                        start: '',
                        end: '',
                        status: '',
                        tag: '',
                    },
                    list: [],
                    loading: false,
                    page: 1,
                    perpage: 10,
                    totalPage: 1,
                    totalRows: 0,
                }
            },
            mounted() {
                this.getList();
            },
            watch: {
            },
            computed: {
            },
            methods: {
                async getList() {
                    this.loading = true;
                    try {
                        const response = await axios.get(this.route.list, {
                            params: {
                                page: this.page,
                                perpage: this.perpage,
                                ...this.condition,
                            }
                        });
                        if (response.data.status === 'success') {
                            this.list = response.data.data;
                            this.totalRows = response.data.total;
                            this.page = response.data.current_page;
                            this.totalPage = response.data.last_page;
                        }
                    } catch (error) {
                        console.log(error);
                    } finally {
                        this.loading = false;
                    }
                },
                changePage(page) {
                    if (page < 1 || page > this.totalPage) return;
                    this.page = page;
                    this.getList();
                },
                deletebox(id) {
                    console.log("Delete article ID:", id);
                },
                // 轉換成 Y/m/d H:i:s 格式
                formatDate(date) {
                    return new Date(date).toLocaleString('zh-TW', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: false,
                    });
                },
                edit(id) {
                    window.location.href = this.route.edit.replace(':id', id);
                },
            },
        });
        const vm = app.mount('#app');
    </script>
@endsection