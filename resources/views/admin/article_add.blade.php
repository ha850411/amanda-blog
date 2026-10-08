@extends('admin/layouts/base')

@section('title')
    <title>後台-文章管理</title>
@endsection

@section('content')
    @include('admin/layouts/menu')

    <div class="main">
        @include('admin/layouts/header')
        <div class="contain p-4">
            <template v-if="initial">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">loading...</span>
                </div>
                <span class="ms-2 text-secondary">loading...</span>
            </template>
            <template v-else>
                <nav aria-label="breadcrumb mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route("admin.index") }}">首頁</a></li>
                        <li class="breadcrumb-item"><a href="{{ route("admin.article") }}">文章管理</a></li>
                        <li class="breadcrumb-item active" aria-current="page">@{{ title }}</li>
                    </ol>
                </nav>
                <h4>文章管理-@{{ title }}</h4>
                <div class="p-3">
                    <div class="mt-2">
                        <label for="exampleFormControlInput1" class="form-label">
                            <span class="text-danger">*</span>標題內容
                        </label>
                        <input type="text" class="form-control" placeholder="請輸入您的標題內容" v-model="form.title">
                    </div>
                    <div class="mt-3">
                        <label class="form-label d-flex justify-content-between align-items-center mb-1">
                            <span>標題徽章 (如已歇業、已搬遷等)</span>
                            <div>
                                <a href="{{ route('admin.badge') }}" target="_blank" class="btn btn-sm btn-outline-secondary me-1">
                                    <i class="fa-solid fa-gear me-1"></i>徽章管理
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-primary" @click="showNewBadgeModal = !showNewBadgeModal">
                                    <i class="fa-solid fa-plus me-1"></i>快速建立徽章
                                </button>
                            </div>
                        </label>

                        {{-- 建立新徽章小表單 --}}
                        <div v-if="showNewBadgeModal" class="p-3 mb-2 border rounded bg-light">
                            <div class="fw-bold mb-2 small">快速建立新徽章</div>
                            <div class="row g-2 align-items-center">
                                <div class="col-sm-4 col-12">
                                    <input type="text" class="form-control form-control-sm" placeholder="徽章名稱 (如：必比登推薦)" v-model="newBadge.name">
                                </div>
                                <div class="col-sm-5 col-12">
                                    <div class="input-group input-group-sm">
                                        <input type="color" class="form-control form-control-color" v-model="newBadgeColorPicker" @input="onNewBadgeColorPickerChange" title="點擊開啟調色盤">
                                        <input type="text" class="form-control" placeholder="#dc3545 或 danger" v-model="newBadge.color">
                                    </div>
                                </div>
                                <div class="col-sm-3 col-12">
                                    <button type="button" class="btn btn-sm btn-success w-100" @click="createNewBadge">儲存並選取</button>
                                </div>
                            </div>
                        </div>

                        {{-- 快速點選/取消徽章 --}}
                        <div class="p-2 border rounded bg-light mb-2">
                            <span class="text-secondary small d-block mb-1">點擊快速加入 / 取消：</span>
                            <div class="d-flex flex-wrap gap-1">
                                <button type="button"
                                    v-for="badge in allBadges" :key="badge.id"
                                    :class="badgeButtonClass(badge)"
                                    :style="badgeButtonStyle(badge)"
                                    @click="toggleBadge(badge)">
                                    <i :class="isBadgeSelected(badge.id) ? 'fa-solid fa-check me-1' : 'fa-solid fa-plus me-1'"></i>@{{ badge.name }}
                                </button>
                                <span v-if="allBadges.length === 0" class="text-muted small">尚無可用徽章</span>
                            </div>
                        </div>

                        {{-- 前台標題預覽 --}}
                        <div class="p-2 border rounded bg-white" v-if="form.selectedBadges.length > 0 || form.title">
                            <div class="text-secondary small mb-1">前台標題即時預覽：</div>
                            <h5 class="m-0">
                                <span v-for="b in form.selectedBadges" :key="b.id"
                                    :class="['badge me-1 align-middle', isHexColor(b.color) ? 'text-white' : ('bg-' + (b.color || 'danger'))]"
                                    :style="isHexColor(b.color) ? { backgroundColor: b.color, color: '#fff' } : {}">
                                    @{{ b.name }}
                                </span>
                                <span class="align-middle">@{{ form.title || '(文章標題)' }}</span>
                            </h5>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">
                            <span class="text-danger">*</span>文章標籤
                        </label>
                        <template v-if="form.selectedTags.length > 0">
                            <div class="mb-2">
                                <button type="button" class="btn btn-success btn-md me-2 mb-2"
                                    v-for="(tag, index) in form.selectedTags" :key="tag.id" @click="removeTag(index)">
                                    @{{ tag.name }}<i class="fa-solid fa-circle-xmark mx-1"></i>
                                </button>
                            </div>
                        </template>
                        <div class="dropdown position-relative">
                            <input type="text" class="form-control" placeholder="搜尋或選擇標籤..." ref="tagInput"
                                v-model="searchTag" @focus="showTagDropdown = true" @blur="hideTagDropdown">

                            <ul class="dropdown-menu w-100" :class="{ show: showTagDropdown }"
                                style="max-height: 200px; overflow-y: auto; position: absolute; top: 100%; left: 0; z-index: 1000;">
                                <li v-for="tag in filteredTags" :key="tag.id">
                                    <a class="dropdown-item" href="javascript:void(0)"
                                        @mousedown.prevent="selectTag(tag)">@{{ tag.display }}</a>
                                </li>
                                <li v-if="filteredTags.length === 0">
                                    <span class="dropdown-item text-muted">查無標籤</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">
                            <span class="text-danger">*</span>文章狀態
                        </label>
                        <select class="form-select mb-3" v-model="form.status">
                            <option selected>請選擇文章狀態</option>
                            <option value="1">公開</option>
                            <option value="2">密碼</option>
                            <option value="3">隱藏</option>
                        </select>
                    </div>
                    <template v-if="form.status == 2">
                        <div class="mt-2">
                            <label class="form-label">
                                <span class="text-danger">*</span>密碼設定
                            </label>
                            <input type="text" class="form-control" placeholder="請輸入您的密碼" v-model="form.password">
                        </div>
                    </template>
                    <div class="mt-2">
                        <label class="form-label">
                            <span class="text-danger">*</span>文章內容
                        </label>
                        <div class="position-relative" style="min-height: 50px;">
                            {{-- ckeditor loading 效果 --}}
                            <div v-if="!initEditor"
                                class="position-absolute top-0 start-0 w-100 h-100 d-flex justify-content-center align-items-center bg-light"
                                style="z-index: 10; border: 1px solid #ccced1; border-radius: 4px;">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">載入中...</span>
                                </div>
                                <span class="ms-2 text-secondary">編輯器載入中...</span>
                            </div>
                            {{-- ckeditor content --}}
                            <div class="main-container w-100"
                                :style="{ opacity: initEditor ? 1 : 0, transition: 'opacity 0.3s ease' }">
                                <div id="editor">@{{ form.content }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-center">
                        <button type="button" class="btn btn-secondary me-1" @click="cancel()">取消</button>
                        <button type="button" class="btn btn-primary" @click="confirm()">確認</button>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endsection

@section('scripts')
    <link rel="stylesheet" href="{{ asset('js/ckeditor5/ckeditor5.css') }}">
    <script src="{{ asset('js/ckeditor5/translations/zh.umd.js') }}"></script>
    <script src="{{ asset('js/ckeditor5/ckeditor5.umd.js') }}"></script>
    <script src="{{ asset('js/ckeditor5/main.js') }}"></script>
    <script>
        const app = Vue.createApp({
            mixins: [baseMixin],
            data() {
                return {
                    initial: true,
                    tags: @json($tags),
                    badges: @json($badges ?? []),
                    article: @json($article),
                    allTags: [],
                    allBadges: [],
                    showNewBadgeModal: false,
                    newBadge: {
                        name: '',
                        color: '#dc3545',
                    },
                    newBadgeColorPicker: '#dc3545',
                    form: {
                        title: '',
                        selectedTags: [],
                        selectedBadges: [],
                        status: 1,
                        password: '',
                        content: '',
                    },
                    searchTag: '',
                    showTagDropdown: false,
                    availableTags: [],
                    initEditor: false,
                    route: {
                        article: '{{ route('admin.article') }}',
                        submit: '{{ route('api.article.store') }}',
                        createBadge: '{{ route('api.badge.store') }}',
                    },
                    title: ''
                }
            },
            mounted() {
                this.init();
            },
            watch: {
            },
            computed: {
                filteredTags() {
                    return this.availableTags.filter(tag => {
                        const isSelected = this.form.selectedTags.some(selected => selected.id === tag.id);
                        if (isSelected) return false;
                        return tag.display.toLowerCase().includes(this.searchTag.toLowerCase());
                    });
                }
            },
            methods: {
                init() {
                    this.allBadges = [...this.badges];
                    this.tags.forEach(tag => {
                        this.availableTags.push({
                            id: tag.id,
                            name: tag.name,
                            display: tag.name
                        });
                        // 有子群組
                        if (tag.children.length > 0) {
                            tag.children.forEach(child => {
                                this.availableTags.push({
                                    id: child.id,
                                    name: child.name,
                                    display: tag.name + ' > ' + child.name
                                });
                            });
                        }
                    });
                    // init form
                    this.title = '新增';
                    if (this.article) {
                        this.title = '修改';
                        this.form.title = this.article.title;
                        this.form.selectedTags = this.article.tags || [];
                        this.form.selectedBadges = this.article.badges || [];
                        this.form.status = this.article.status;
                        this.form.password = this.article.password;
                        this.form.content = this.article.content;
                    }
                    // init ckeditor
                    this.$nextTick(() => {
                        InitCKEditor.init('#editor').then(() => {
                            this.initEditor = true;
                            if (this.form.content) {
                                window.myEditor.setData(this.form.content);
                            }
                        });
                    });
                    this.initial = false;
                },
                isBadgeSelected(badgeId) {
                    return this.form.selectedBadges.some(b => b.id === badgeId);
                },
                badgeButtonClass(badge) {
                    const isSelected = this.isBadgeSelected(badge.id);
                    if (!isSelected) return 'btn btn-outline-secondary btn-sm';
                    if (this.isHexColor(badge.color)) return 'btn btn-sm text-white';
                    return 'btn btn-' + (badge.color || 'danger') + ' btn-sm';
                },
                badgeButtonStyle(badge) {
                    if (this.isBadgeSelected(badge.id) && this.isHexColor(badge.color)) {
                        return {
                            backgroundColor: badge.color,
                            borderColor: badge.color,
                            color: '#fff'
                        };
                    }
                    return {};
                },
                isHexColor(color) {
                    return typeof color === 'string' && (color.startsWith('#') || color.startsWith('rgb'));
                },
                onNewBadgeColorPickerChange() {
                    this.newBadge.color = this.newBadgeColorPicker;
                },
                toggleBadge(badge) {
                    const index = this.form.selectedBadges.findIndex(b => b.id === badge.id);
                    if (index > -1) {
                        this.form.selectedBadges.splice(index, 1);
                    } else {
                        this.form.selectedBadges.push(badge);
                    }
                },
                async createNewBadge() {
                    if (!this.newBadge.name.trim()) {
                        this.showError('請輸入徽章名稱');
                        return;
                    }
                    try {
                        const res = await axios.post(this.route.createBadge, {
                            name: this.newBadge.name.trim(),
                            color: this.newBadge.color || 'danger',
                        });
                        if (res.data.status === 'success') {
                            const created = res.data.data;
                            this.allBadges.push(created);
                            this.form.selectedBadges.push(created);
                            this.newBadge.name = '';
                            this.showNewBadgeModal = false;
                            Swal.fire({
                                icon: 'success',
                                title: '徽章建立成功並已選取',
                                showConfirmButton: false,
                                timer: 1200
                            });
                        }
                    } catch (e) {
                        this.showError(e.response?.data?.message || '建立徽章失敗');
                    }
                },
                hideTagDropdown() {
                    setTimeout(() => {
                        this.showTagDropdown = false;
                    }, 150);
                },
                selectTag(tag) {
                    this.form.selectedTags.push(tag);
                    this.searchTag = '';
                    this.showTagDropdown = false;
                    this.$refs.tagInput.blur(); // cancel focus
                },
                removeTag(index) {
                    this.form.selectedTags.splice(index, 1);
                },
                cancel() {
                    window.location.href = this.route.article;
                },
                async confirm() {
                    if (!this.checkForm()) return;
                    axios.post(this.route.submit, {
                        id: this.article?.id ?? null,
                        title: this.form.title,
                        selectedTags: this.form.selectedTags,
                        selectedBadges: this.form.selectedBadges,
                        status: this.form.status,
                        password: this.form.password,
                        content: window.myEditor.getData(),
                    })
                        .then(response => {
                            Swal.fire({
                                icon: 'success',
                                title: `${this.title}成功`,
                                showConfirmButton: false,
                                timer: 1500
                            });
                            this.cancel();
                        })
                        .catch(error => {
                            this.showError(error.response.data.message);
                        });
                },
                checkForm() {
                    if (this.form.title === '') {
                        this.showError('請輸入文章標題');
                        return false;
                    }
                    if (this.form.status === '') {
                        this.showError('請選擇文章狀態');
                        return false;
                    }
                    if (this.form.status == 2 && !this.form.password) {
                        this.showError('請輸入文章密碼');
                        return false;
                    }
                    if (window.myEditor.getData() === '') {
                        this.showError('請輸入文章內容');
                        return false;
                    }
                    return true;
                },
                showError(message) {
                    Swal.fire({
                        icon: 'error',
                        title: '錯誤',
                        text: message,
                        showConfirmButton: false,
                        timer: 1500
                    });
                }
            },
        });
        const vm = app.mount('#app');
    </script>
@endsection