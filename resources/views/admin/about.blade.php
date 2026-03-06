@extends('admin/layouts/base')

@section('title')
<title>後台-個人資料</title>
@endsection

@section('content')
    @include('admin/layouts/menu')

    <div class="main">
        @include('admin/layouts/header')
        <div class="contain p-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-4">
                    <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">個人資料</li>
                </ol>
            </nav>

            {{-- ========== 標題管理 ========== --}}
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="fa-solid fa-heading me-2"></i>標題管理</span>
                    <button v-if="!editing.title" type="button" class="btn btn-sm btn-outline-light" @click="startEdit('title')">
                        <i class="fa-solid fa-pen-to-square me-1"></i>編輯
                    </button>
                </div>
                <div class="card-body">
                    {{-- 檢視模式 --}}
                    <div v-if="!editing.title" class="row">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <div class="text-muted small mb-1">標題內容</div>
                            <div class="fs-5 fw-semibold">@{{ form.title || '—' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small mb-1">副標內容</div>
                            <div class="fs-5" style="white-space: pre-line">@{{ form.subtitle || '—' }}</div>
                        </div>
                    </div>
                    {{-- 編輯模式 --}}
                    <div v-else>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">標題內容</label>
                                <input type="text" class="form-control" v-model="form.title" placeholder="請輸入您的標題內容">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">副標內容</label>
                                <textarea class="form-control" rows="3" v-model="form.subtitle" placeholder="請輸入您的副標內容"></textarea>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-secondary" @click="cancelEdit('title')">
                                <i class="fa-solid fa-xmark me-1"></i>取消
                            </button>
                            <button type="button" class="btn btn-sm btn-primary" @click="saveSection('title')">
                                <i class="fa-solid fa-check me-1"></i>儲存
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ========== 關於我 ========== --}}
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="fa-solid fa-user me-2"></i>關於我</span>
                    <button v-if="!editing.about" type="button" class="btn btn-sm btn-outline-light" @click="startEdit('about')">
                        <i class="fa-solid fa-pen-to-square me-1"></i>編輯
                    </button>
                </div>
                <div class="card-body">
                    {{-- 檢視模式 --}}
                    <div v-if="!editing.about" class="d-flex align-items-center">
                        <div class="me-4 flex-shrink-0">
                            <img :src="avatarPreview"
                                 class="rounded-circle border"
                                 alt="頭像"
                                 style="width: 80px; height: 80px; object-fit: cover;">
                        </div>
                        <div>
                            <div class="text-muted small mb-1">介紹內容</div>
                            <div v-html="form.description" style="white-space: pre-line"></div>
                        </div>
                    </div>
                    {{-- 編輯模式 --}}
                    <div v-else>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-semibold">頭像圖片</label>
                                <input type="file" class="form-control" @change="onAvatarChange">
                                <div class="mt-2">
                                    <img :src="avatarPreview"
                                         class="rounded-circle border"
                                         style="width: 60px; height: 60px; object-fit: cover;"
                                         alt="預覽">
                                </div>
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label fw-semibold">介紹內容</label>
                                <textarea class="form-control" rows="3" v-model="form.description" placeholder="請輸入您的介紹內容"></textarea>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-secondary" @click="cancelEdit('about')">
                                <i class="fa-solid fa-xmark me-1"></i>取消
                            </button>
                            <button type="button" class="btn btn-sm btn-primary" @click="saveSection('about')">
                                <i class="fa-solid fa-check me-1"></i>儲存
                            </button>
                        </div>
                    </div>
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
            editing: {
                title: false,
                about: false,
            },
            avatarPreview: '{{ $about->picture }}',
            form: {
                title: @json($about->title ?? ''),
                subtitle: @json($about->sub_title ?? ''),
                description: @json($about->description ?? ''),
                avatar: null,
            },
            // 暫存原始值，取消時可還原
            formBackup: {},
            api: {
                update: '{{ route('api.updateAbout') }}',
            }
        }
    },
    methods: {
        startEdit(section) {
            // 備份目前的值，取消時還原
            this.formBackup = JSON.parse(JSON.stringify(this.form));
            this.editing[section] = true;
        },
        cancelEdit(section) {
            // 還原備份的值
            Object.assign(this.form, JSON.parse(JSON.stringify(this.formBackup)));
            this.editing[section] = false;
        },
        onAvatarChange(e) {
            const file = e.target.files[0];
            if (file) {
                this.form.avatar = file;
                this.avatarPreview = URL.createObjectURL(file);
            }
        },
        saveSection(section) {
            // todo: 送出 API
            this.editing[section] = false;

            const formData = new FormData();
            switch (section) {
                case 'title':
                    formData.append('title', this.form.title);
                    formData.append('sub_title', this.form.subtitle);
                    break;
                case 'about':
                    formData.append('description', this.form.description);
                    if (this.form.avatar instanceof File) {
                        formData.append('avatar', this.form.avatar);
                    }
                    break;
            }

            axios.post(this.api.update, formData)
                .then(response => {
                    Swal.fire({
                        icon: 'success',
                        title: '更新成功',
                        showConfirmButton: false,
                        timer: 1500
                    });
                    if (section === 'about') {
                        this.$refs.headerAvatar.src = this.avatarPreview;
                    }
                })
                .catch(error => {
                    this.showError(error.response.data.message);
                });
        },
    },
});
const vm = app.mount('#app');
</script>
@endsection