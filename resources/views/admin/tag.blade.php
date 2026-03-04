@extends('admin/layouts/base')

@section('title')
    <title>後台-標籤管理</title>
@endsection

@section('style')
<style>
    .sort-btn {
        padding: 0.1rem 0.4rem;
        line-height: 1;
        font-size: 0.85rem;
        border-radius: 4px;
        transition: all 0.15s ease;
    }

    .sort-btn:not(:disabled):hover {
        background-color: #0d6efd;
        border-color: #0d6efd;
        color: #fff;
    }

    .sort-btn:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }

    .child-tag-item {
        gap: 2px;
    }

    .child-sort-btn {
        padding: 0.05rem 0.25rem;
        line-height: 1;
        font-size: 0.7rem;
        border: 1px solid #ccc;
        background: transparent;
        color: #888;
        border-radius: 3px;
        transition: all 0.15s ease;
    }

    .child-sort-btn:not(:disabled):hover {
        background-color: #198754;
        border-color: #198754;
        color: #fff;
    }

    .child-sort-btn:disabled {
        opacity: 0.2;
        cursor: not-allowed;
        border-color: #ddd;
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
                    <li class="breadcrumb-item"><a href="index.html">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">標籤管理</li>
                </ol>
            </nav>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-primary btn-md" @click="add()">新增</button>
                <button type="button" class="btn btn-md" :class="sortMode ? 'btn-success' : 'btn-outline-secondary'" @click="toggleSortMode()">
                    <i class="fa-solid fa-sort me-1"></i>排序
                </button>
            </div>

            <h4 class="mt-3">標籤管理</h4>
            <table class="table table-hover">
                <thead class="table-dark">
                    <tr>
                        <td v-if="sortMode" style="width: 60px;">排序</td>
                        <td>主要選單</td>
                        <td>子選單</td>
                        <td>操作</td>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(tag, index) in tags" :key="tag.id">
                        <td v-if="sortMode" class="align-middle">
                            <div class="d-flex flex-column align-items-center gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary sort-btn"
                                    :disabled="index === 0 || sorting" @click="updateSort(tag.id, 'up')" title="上移">
                                    <i class="fa-solid fa-caret-up"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary sort-btn"
                                    :disabled="index === tags.length - 1 || sorting" @click="updateSort(tag.id, 'down')"
                                    title="下移">
                                    <i class="fa-solid fa-caret-down"></i>
                                </button>
                            </div>
                        </td>
                        <td class="align-middle">@{{ tag.name }}</td>
                        <td class="align-middle">
                            <div class="d-flex flex-wrap align-items-center gap-1">
                                <div v-for="(child, childIndex) in tag.children" :key="child.id"
                                    class="child-tag-item d-inline-flex align-items-center">
                                    <button v-if="sortMode" type="button" class="btn btn-sm child-sort-btn"
                                        :disabled="childIndex === 0 || sorting" @click="updateSort(child.id, 'up')"
                                        title="前移">
                                        <i class="fa-solid fa-caret-left"></i>
                                    </button>
                                    <span class="badge bg-success">
                                        @{{ child.name }}
                                    </span>
                                    <button v-if="sortMode" type="button" class="btn btn-sm child-sort-btn"
                                        :disabled="childIndex === tag.children.length - 1 || sorting"
                                        @click="updateSort(child.id, 'down')" title="後移">
                                        <i class="fa-solid fa-caret-right"></i>
                                    </button>
                                </div>
                            </div>
                            <div v-if="!tag.children || tag.children.length === 0" class="text-muted small">—</div>
                        </td>
                        <td class="align-middle">
                            <button type="button" class="btn btn-sm btn-secondary me-1" @click="edit(tag)">編輯</button>
                            <button type="button" class="btn btn-sm btn-danger" @click="confirmDelete(tag)">刪除</button>
                        </td>
                    </tr>
                    <tr v-if="tags.length === 0">
                        <td :colspan="sortMode ? 4 : 3" class="text-center text-muted py-4">尚無任何標籤</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- 刪除確認 Modal --}}
    <div class="model" ref="delete_model" @click.self="modal('delete_model', 'hide')">
        <div class="deletebox rounded shadow-lg bg-white text-center">
            <div class="rounded-top bg-light text-center p-2">
                <div class="text-secondary fw-bolder">刪除</div>
            </div>
            <div class="p-3">
                <div>確認刪除「<strong>@{{ deleteTarget?.name }}</strong>」及其所有子選單?</div>
                <div class="mt-3">
                    <button type="button" class="btn btn-secondary me-2" @click="modal('delete_model', 'hide')">取消</button>
                    <button type="button" class="btn btn-danger" :disabled="submitting" @click="handleDelete()">
                        <span v-if="submitting">
                            <i class="fa-solid fa-spinner fa-spin me-1"></i>處理中...
                        </span>
                        <span v-else>確認刪除</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- 新增/編輯 Modal --}}
    <div class="model" ref="edit_add_model" @click.self="modal('edit_add_model', 'hide')">
        <div class="edit_add_box rounded shadow-lg bg-white">
            <div class="rounded-top bg-light text-center p-2">
                <div class="text-secondary fw-bolder">@{{ isEditMode ? '編輯標籤' : '新增標籤' }}</div>
            </div>
            <div class="p-3">
                <div class="mt-2">
                    <label class="form-label fw-bold">主要選單</label>
                    <input type="text" class="form-control" v-model="form.name" placeholder="請輸入您的主要選單名稱"
                        @keyup.enter="$refs.childInput.focus()">
                </div>
                <div class="mt-3">
                    <label class="form-label fw-bold">子選單</label>
                    <div class="input-group">
                        <input type="text" class="form-control" ref="childInput" v-model="childInput"
                            placeholder="輸入子選單名稱後按 Enter 或點新增" @keyup.enter="addChild()">
                        <button class="btn btn-outline-success" type="button" @click="addChild()">
                            <i class="fa-solid fa-plus me-1"></i>新增
                        </button>
                    </div>
                    <div class="mt-2 d-flex flex-wrap gap-1" v-if="form.children.length > 0">
                        <span v-for="(child, index) in form.children" :key="index"
                            class="badge bg-success d-inline-flex align-items-center gap-1 py-2 px-3"
                            style="font-size: 0.85rem;">
                            @{{ child }}
                            <i class="fa-solid fa-circle-xmark ms-1" style="cursor: pointer; opacity: 0.8;"
                                @click="removeChild(index)" @mouseover="$event.target.style.opacity = 1"
                                @mouseleave="$event.target.style.opacity = 0.8"></i>
                        </span>
                    </div>
                    <div v-else class="text-muted small mt-2">尚未新增任何子選單</div>
                </div>
                <div class="mt-3 text-center">
                    <button type="button" class="btn btn-secondary me-2"
                        @click="modal('edit_add_model', 'hide')">取消</button>
                    <button type="button" class="btn btn-primary" :disabled="!form.name.trim() || submitting"
                        @click="handleSubmit()">
                        <span v-if="submitting">
                            <i class="fa-solid fa-spinner fa-spin me-1"></i>處理中...
                        </span>
                        <span v-else>@{{ isEditMode ? '儲存變更' : '確認新增' }}</span>
                    </button>
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
            tags: @json($tags),
            sorting: false,
            sortMode: false,
            submitting: false,

            // 表單
            isEditMode: false,
            editingTagId: null,
            form: {
                name: '',
                children: [],
            },
            childInput: '',

            // 刪除
            deleteTarget: null,
        }
    },
    methods: {
        // ── 排序模式切換 ──
        toggleSortMode() {
            this.sortMode = !this.sortMode;
        },

        // ── 新增 ──
        add() {
            this.isEditMode = false;
            this.editingTagId = null;
            this.form = { name: '', children: [] };
            this.childInput = '';
            this.modal('edit_add_model', 'show');
            this.$nextTick(() => {
                this.$refs.childInput?.parentElement?.previousElementSibling?.querySelector('input')?.focus();
            });
        },

        // ── 編輯 ──
        edit(tag) {
            this.isEditMode = true;
            this.editingTagId = tag.id;
            this.form = {
                name: tag.name,
                children: tag.children ? tag.children.map(c => c.name) : [],
            };
            this.childInput = '';
            this.modal('edit_add_model', 'show');
        },

        // ── 子選單管理 ──
        addChild() {
            const name = this.childInput.trim();
            if (!name) return;
            if (this.form.children.includes(name)) {
                Swal.fire({
                    icon: 'warning',
                    title: '子選單名稱重複',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 1500,
                });
                return;
            }
            this.form.children.push(name);
            this.childInput = '';
            this.$refs.childInput?.focus();
        },
        removeChild(index) {
            this.form.children.splice(index, 1);
        },

        // ── 送出 (新增/編輯) ──
        async handleSubmit() {
            if (this.submitting) return;
            this.submitting = true;

            try {
                let res;
                if (this.isEditMode) {
                    res = await axios.put(`/api/admin/tag/${this.editingTagId}`, {
                        name: this.form.name.trim(),
                        children: this.form.children,
                    });
                } else {
                    res = await axios.post('/api/admin/tag', {
                        name: this.form.name.trim(),
                        children: this.form.children,
                    });
                }

                if (res.data.success) {
                    this.tags = res.data.tags;
                    this.modal('edit_add_model', 'hide');
                    Swal.fire({
                        icon: 'success',
                        title: res.data.message,
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 1500,
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: res.data.message || '操作失敗',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 2000,
                    });
                }
            } catch (error) {
                const msg = error.response?.data?.message || '操作失敗，請稍後再試';
                Swal.fire({
                    icon: 'error',
                    title: msg,
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                });
            } finally {
                this.submitting = false;
            }
        },

        // ── 刪除 ──
        confirmDelete(tag) {
            this.deleteTarget = tag;
            this.modal('delete_model', 'show');
        },
        async handleDelete() {
            if (this.submitting || !this.deleteTarget) return;
            this.submitting = true;

            try {
                const res = await axios.delete(`/api/admin/tag/${this.deleteTarget.id}`);

                if (res.data.success) {
                    this.tags = res.data.tags;
                    this.modal('delete_model', 'hide');
                    Swal.fire({
                        icon: 'success',
                        title: res.data.message,
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 1500,
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: res.data.message || '刪除失敗',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 2000,
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: '刪除失敗，請稍後再試',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                });
            } finally {
                this.submitting = false;
                this.deleteTarget = null;
            }
        },

        // ── 排序 ──
        async updateSort(id, direction) {
            if (this.sorting) return;
            this.sorting = true;

            try {
                const res = await axios.post('/api/admin/tag/sort', {
                    id: id,
                    direction: direction,
                });

                if (res.data.success) {
                    this.tags = res.data.tags;
                } else {
                    Swal.fire({
                        icon: 'info',
                        title: res.data.message,
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 1500,
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: '排序更新失敗',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 1500,
                });
            } finally {
                this.sorting = false;
            }
        },
    },
});
const vm = app.mount('#app');
</script>
@endsection