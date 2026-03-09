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
                  <li class="breadcrumb-item"><a href="{{ route("admin.index") }}">首頁</a></li>
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
                    <tr v-if="loading">
                        <td colspan="4" class="text-center">載入中...</td>
                    </tr>
                    <tr v-if="!loading && list.length === 0">
                        <td colspan="4" class="text-center">無資料</td>
                    </tr>
                    <tr v-for="(item, index) in list" :key="index">
                        <td>
                            <i class="fa-2x" :class="item.icon"></i>
                        </td>
                        <td>@{{ item.url }}</td>
                        <td>@{{ item.status == 1 ? '公開' : '隱藏' }}</td>
                        <td><button type="button" class="btn btn-sm btn-secondary" @click="editaddbox(item)">編輯</button></td>
                    </tr>
                </tbody>
                
            </table>
        </div>
    </div>
    <div class="model" ref="edit_add_model">
        <div class="edit_add_box rounded shadow-lg bg-white">
            <div class="rounded-top bg-light text-center p-2">
                <div class="text-secondary fw-bolder">編輯</div>
            </div>
            <div class="p-3">
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">社群圖片</label>
                    <div>
                        <i class="fa-2x" :class="form.icon" class="mb-2"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <label for="exampleFormControlInput1" class="form-label">網址連結</label>
                    <input type="text" class="form-control" id="exampleFormControlInput1" placeholder="請輸入您的網址連結" v-model="form.url">
                </div>
                <div class="mt-2">
                    <label for="selectState" class="form-label">狀態</label>
                    <select class="form-select" id="selectState" v-model="form.status">
                        <option value="1">公開</option>
                        <option value="0">隱藏</option>
                    </select>
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
            route: {
                list: '{{ route("api.social.index") }}',
                update: '{{ route("api.social.update", ["id" => ":id"]) }}',
            },
            list: [],
            loading: false,
            form: {
                id: null,
                icon: null,
                url: '',
                status: 1,
            }
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
                const res = await axios.get(this.route.list);
                this.list = res.data.data;
            } catch (error) {
                console.error(error);
            } finally {
                this.loading = false;
            }
        },
        editaddbox(item) {
            this.modal('edit_add_model', 'show');
            this.form = {
                id: item.id,
                icon: item.icon,
                url: item.url,
                status: item.status,
            };
        },
        confirm() {
            // todo
            const url = this.route.update.replace(':id', this.form.id);
                axios.patch(url, {
                    url: this.form.url,
                    status: this.form.status,
                }).then(res => {
                    Swal.fire({
                        icon: 'success',
                        title: '更新成功',
                        text: res.data.message || '已成功更新',
                    });
                    this.getList();
                }).catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: '更新失敗',
                        text: error.response?.data?.message || '請稍後再試',
                    });
                });
            this.modal('edit_add_model', 'hide');
        }
    },
});
const vm = app.mount('#app');
</script>
@endsection