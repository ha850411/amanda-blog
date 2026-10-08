@extends('admin/layouts/base')

@section('title')
<title>後台-徽章管理</title>
@endsection

@section('styles')
<style>
.color-circle {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: inline-block;
    cursor: pointer;
    border: 2px solid transparent;
    transition: transform 0.15s ease;
}
.color-circle:hover {
    transform: scale(1.15);
}
.color-circle.active {
    border-color: #000;
    box-shadow: 0 0 4px rgba(0,0,0,0.4);
}
</style>
@endsection

@section('content')
    @include('admin/layouts/menu')

    <div class="main">
        @include('admin/layouts/header')
        <div class="contain p-4">
            <nav aria-label="breadcrumb mb-3">
                <ol class="breadcrumb">
                  <li class="breadcrumb-item"><a href="{{ route("admin.index") }}">首頁</a></li>
                  <li class="breadcrumb-item active" aria-current="page">徽章管理</li>
                </ol>
            </nav>

            <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                <h4 class="m-0">標題徽章管理</h4>
                <button type="button" class="btn btn-primary btn-md" @click="openAddModal()">
                    <i class="fa-solid fa-plus me-1"></i>新增徽章
                </button>
            </div>
            <p class="text-secondary small">此處可管理文章標題前綴的專屬徽章（如：已歇業、已搬遷、二訪等），停用的徽章不會出現在文章編輯器的選單中。</p>

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 70px;">排序</th>
                            <th>徽章名稱</th>
                            <th>前台預覽</th>
                            <th>顏色代碼</th>
                            <th style="width: 100px;">狀態</th>
                            <th>建立時間</th>
                            <th>更新時間</th>
                            <th style="width: 140px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="8" class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">載入中...</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!loading && list.length === 0">
                            <td colspan="8" class="text-center text-secondary py-4">目前尚無徽章資料</td>
                        </tr>
                        <tr v-for="(item, index) in list" :key="item.id">
                            <td>@{{ item.sort }}</td>
                            <td class="fw-bold">@{{ item.name }}</td>
                            <td>
                                <span :class="badgeClass(item.color)" :style="badgeStyle(item.color)" style="font-size: 0.85rem;">
                                    @{{ item.name }}
                                </span>
                            </td>
                            <td>
                                <span class="badge text-dark bg-light border me-1">@{{ item.color }}</span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm"
                                    :class="item.status == 1 ? 'btn-success' : 'btn-secondary'"
                                    @click="toggleStatus(item)">
                                    @{{ item.status == 1 ? '啟用中' : '已停用' }}
                                </button>
                            </td>
                            <td class="small text-secondary">@{{ formatDate(item.created_at) }}</td>
                            <td class="small text-secondary">@{{ formatDate(item.updated_at) }}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-secondary me-1" @click="openEditModal(item)">
                                    <i class="fa-solid fa-pen-to-square"></i> 編輯
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" @click="deleteBadge(item)">
                                    <i class="fa-solid fa-trash"></i> 刪除
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- 新增 / 編輯 Modal --}}
    <div class="model" ref="badge_modal">
        <div class="edit_add_box rounded shadow-lg bg-white" style="max-width: 460px; width: 90%;">
            <div class="rounded-top bg-light text-center p-3 border-bottom">
                <h5 class="m-0 text-dark">@{{ modalMode === 'add' ? '新增徽章' : '編輯徽章' }}</h5>
            </div>
            <div class="p-3">
                <div class="mb-3">
                    <label class="form-label"><span class="text-danger">*</span> 徽章名稱</label>
                    <input type="text" class="form-control" placeholder="例如：已歇業、已搬遷、二訪更新" v-model="form.name">
                </div>

                <div class="mb-3">
                    <label class="form-label d-flex justify-content-between align-items-center">
                        <span><span class="text-danger">*</span> 標籤顏色</span>
                        <span class="small text-secondary">調色盤或 Hex 色碼</span>
                    </label>
                    <div class="input-group mb-2">
                        <input type="color" class="form-control form-control-color" v-model="pickerColor" @input="onColorPickerChange" title="點擊開啟調色盤">
                        <input type="text" class="form-control" placeholder="#dc3545 或 danger" v-model="form.color" @input="onColorTextChange">
                    </div>
                    {{-- 推薦常用色票 --}}
                    <div class="d-flex align-items-center flex-wrap gap-2 pt-1">
                        <span class="small text-secondary me-1">推薦色：</span>
                        <span v-for="preset in presetColors" :key="preset.color"
                            class="color-circle"
                            :style="{ backgroundColor: preset.color }"
                            :class="{ active: form.color === preset.color || form.color === preset.name }"
                            :title="preset.label"
                            @click="selectPresetColor(preset)">
                        </span>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label">排序 (由小到大)</label>
                        <input type="number" class="form-control" v-model.number="form.sort" min="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label">啟用狀態</label>
                        <select class="form-select" v-model.number="form.status">
                            <option :value="1">啟用</option>
                            <option :value="0">停用</option>
                        </select>
                    </div>
                </div>

                {{-- 即時預覽效果 --}}
                <div class="p-2 border rounded bg-light mb-3">
                    <div class="small text-secondary mb-1">即時預覽效果：</div>
                    <h5 class="m-0">
                        <span :class="badgeClass(form.color)" :style="badgeStyle(form.color)">
                            @{{ form.name || '徽章名稱' }}
                        </span>
                        <span class="text-muted ms-1 fs-6">文章標題範例</span>
                    </h5>
                </div>

                <div class="text-center pt-2 border-top">
                    <button type="button" class="btn btn-secondary me-2" @click="modal('badge_modal', 'hide')">取消</button>
                    <button type="button" class="btn btn-primary" @click="submitBadge()">儲存</button>
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
                list: '{{ route("api.badge.index") }}',
                store: '{{ route("api.badge.store") }}',
                update: '{{ route("api.badge.update", ["id" => ":id"]) }}',
                toggle: '{{ route("api.badge.toggleStatus", ["id" => ":id"]) }}',
                destroy: '{{ route("api.badge.destroy", ["id" => ":id"]) }}',
            },
            list: [],
            loading: false,
            modalMode: 'add',
            pickerColor: '#dc3545',
            form: {
                id: null,
                name: '',
                color: '#dc3545',
                sort: 0,
                status: 1,
            },
            presetColors: [
                { name: 'danger', color: '#dc3545', label: '紅色 (歇業/緊急)' },
                { name: 'warning', color: '#fd7e14', label: '橘色 (搬遷/注意)' },
                { name: 'yellow', color: '#ffc107', label: '黃色 (提醒)' },
                { name: 'success', color: '#198754', label: '綠色 (推薦/開幕)' },
                { name: 'primary', color: '#0d6efd', label: '藍色 (二訪/更新)' },
                { name: 'purple', color: '#6f42c1', label: '紫色 (特色)' },
                { name: 'secondary', color: '#6c757d', label: '灰色 (暫停營業)' },
                { name: 'dark', color: '#212529', label: '黑色 (特別)' },
            ]
        }
    },
    mounted() {
        this.getList();
    },
    methods: {
        async getList() {
            this.loading = true;
            try {
                const res = await axios.get(this.route.list);
                if (res.data.status === 'success') {
                    this.list = res.data.data;
                }
            } catch (e) {
                this.showError('載入徽章列表失敗');
            } finally {
                this.loading = false;
            }
        },
        badgeStyle(color) {
            if (color && (color.startsWith('#') || color.startsWith('rgb'))) {
                return { backgroundColor: color, color: '#fff' };
            }
            return {};
        },
        badgeClass(color) {
            if (color && (color.startsWith('#') || color.startsWith('rgb'))) {
                return 'badge me-1 align-middle text-white';
            }
            const map = {
                'danger': 'bg-danger',
                'warning': 'bg-warning text-dark',
                'secondary': 'bg-secondary',
                'primary': 'bg-primary',
                'success': 'bg-success',
                'dark': 'bg-dark',
                'info': 'bg-info text-dark',
            };
            return 'badge me-1 align-middle ' + (map[color] || 'bg-danger');
        },
        onColorPickerChange() {
            this.form.color = this.pickerColor;
        },
        onColorTextChange() {
            if (this.form.color && this.form.color.startsWith('#') && this.form.color.length === 7) {
                this.pickerColor = this.form.color;
            }
        },
        selectPresetColor(preset) {
            this.form.color = preset.color;
            this.pickerColor = preset.color;
        },
        openAddModal() {
            this.modalMode = 'add';
            const maxSort = this.list.length > 0 ? Math.max(...this.list.map(i => i.sort || 0)) + 1 : 1;
            this.form = {
                id: null,
                name: '',
                color: '#dc3545',
                sort: maxSort,
                status: 1,
            };
            this.pickerColor = '#dc3545';
            this.modal('badge_modal', 'show');
        },
        openEditModal(item) {
            this.modalMode = 'edit';
            this.form = {
                id: item.id,
                name: item.name,
                color: item.color,
                sort: item.sort,
                status: item.status,
            };
            this.pickerColor = item.color.startsWith('#') ? item.color : '#dc3545';
            this.modal('badge_modal', 'show');
        },
        async submitBadge() {
            if (!this.form.name.trim()) {
                this.showError('請輸入徽章名稱');
                return;
            }
            if (!this.form.color.trim()) {
                this.showError('請設定徽章顏色');
                return;
            }

            try {
                if (this.modalMode === 'add') {
                    const res = await axios.post(this.route.store, this.form);
                    if (res.data.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: '徽章新增成功',
                            showConfirmButton: false,
                            timer: 1200
                        });
                        this.modal('badge_modal', 'hide');
                        this.getList();
                    }
                } else {
                    const url = this.route.update.replace(':id', this.form.id);
                    const res = await axios.put(url, this.form);
                    if (res.data.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: '徽章更新成功',
                            showConfirmButton: false,
                            timer: 1200
                        });
                        this.modal('badge_modal', 'hide');
                        this.getList();
                    }
                }
            } catch (e) {
                this.showError(e.response?.data?.message || '操作失敗');
            }
        },
        async toggleStatus(item) {
            try {
                const url = this.route.toggle.replace(':id', item.id);
                const res = await axios.patch(url);
                if (res.data.status === 'success') {
                    item.status = res.data.data.status;
                    Swal.fire({
                        icon: 'success',
                        title: res.data.message,
                        showConfirmButton: false,
                        timer: 1000
                    });
                }
            } catch (e) {
                this.showError('切換狀態失敗');
            }
        },
        deleteBadge(item) {
            Swal.fire({
                title: '確認刪除？',
                text: `確定要刪除「${item.name}」徽章嗎？已關聯此徽章的文章將自動解除關聯。`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: '確定刪除',
                cancelButtonText: '取消'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    try {
                        const url = this.route.destroy.replace(':id', item.id);
                        const res = await axios.delete(url);
                        if (res.data.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: '徽章已刪除',
                                showConfirmButton: false,
                                timer: 1200
                            });
                            this.getList();
                        }
                    } catch (e) {
                        this.showError('刪除失敗');
                    }
                }
            });
        },
        showError(message) {
            Swal.fire({
                icon: 'error',
                title: '錯誤',
                text: message,
                showConfirmButton: false,
                timer: 1500
            });
        },
        formatDate(dateStr) {
            if (!dateStr) return '—';
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            const pad = (n) => String(n).padStart(2, '0');
            return `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
        }
    }
});
const vm = app.mount('#app');
</script>
@endsection
