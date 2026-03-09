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
                    article: @json($article),
                    allTags: [],
                    form: {
                        title: '',
                        selectedTags: [],
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
                        this.form.selectedTags = this.article.tags;
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