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
                    <input type="text" class="form-control" placeholder="請輸入您的標題內容" v-model="form.title">
                </div>
                <div class="mt-2">
                    <label class="form-label">文章標籤</label>
                    <template v-if="form.selectedTags.length > 0">
                        <div class="mb-2">
                            <button type="button" class="btn btn-success btn-md me-2 mb-2" v-for="(tag, index) in form.selectedTags" :key="tag.id" @click="removeTag(index)">
                                @{{ tag.name }}<i class="fa-solid fa-circle-xmark mx-1"></i>
                            </button>
                        </div>
                    </template>
                    <div class="dropdown position-relative">
                        <input type="text" class="form-control" placeholder="搜尋或選擇標籤..." 
                            ref="tagInput"
                            v-model="searchTag" 
                            @focus="showTagDropdown = true" 
                            @blur="hideTagDropdown">
                        
                        <ul class="dropdown-menu w-100" :class="{ show: showTagDropdown }" style="max-height: 200px; overflow-y: auto; position: absolute; top: 100%; left: 0; z-index: 1000;">
                            <li v-for="tag in filteredTags" :key="tag.id">
                                <a class="dropdown-item" href="javascript:void(0)" @mousedown.prevent="selectTag(tag)">@{{ tag.display }}</a>
                            </li>
                            <li v-if="filteredTags.length === 0">
                                <span class="dropdown-item text-muted">查無標籤</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="mt-2">
                    <label class="form-label">文章狀態</label>
                    <select class="form-select mb-3" v-model="form.status">
                        <option selected>請選擇文章狀態</option>
                        <option value="1">公開</option>
                        <option value="2">密碼</option>
                        <option value="3">隱藏</option>
                    </select>
                </div>
                <div class="mt-2" v-if="form.status === '2'">
                    <label class="form-label">密碼設定</label>
                    <input type="text" class="form-control" placeholder="請輸入您的密碼" v-model="form.password">
                </div>
                <div class="mt-2">
                    <label class="form-label">文章內容</label>
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
@endsection

@section('scripts')
<link rel="stylesheet" href="{{ asset('js/ckeditor5/ckeditor5.css') }}">
<script src="{{ asset('js/ckeditor5/ckeditor5.umd.js') }}"></script>
<script>
const { 
    ClassicEditor, 
    Essentials, 
    Heading,
    Bold, Italic, Underline, Strikethrough,
    FontColor, FontBackgroundColor,
    Link,
    List, TodoList,
    Alignment,
    BlockQuote,
    ImageUpload, ImageInsert,
    Table, TableToolbar,
    SourceEditing
} = CKEDITOR;

const app = Vue.createApp({
    mixins: [baseMixin],
    data() {
        return {
            tags: @json($tags),
            allTags: [],
            form: {
                title: '',
                selectedTags: [],
                status: '',
                password: '',
                content: '',
            },
            searchTag: '',
            showTagDropdown: false,
            availableTags: []
        }
    },
    mounted() {
        this.init();
        ClassicEditor
            .create( document.querySelector( '#editor' ), {
                licenseKey: 'GPL', 
                plugins: [ 
                    Essentials, Heading, Bold, Italic, Underline, Strikethrough,
                    FontColor, FontBackgroundColor, Link, List, TodoList,
                    Alignment, BlockQuote, ImageUpload, ImageInsert,
                    Table, TableToolbar, SourceEditing
                ],
                toolbar: [
                    'undo', 'redo', '|',
                    'heading', '|',
                    'bold', 'italic', 'underline', 'strikethrough', '|',
                    'fontColor', 'fontBackgroundColor', '|',
                    'alignment', 'bulletedList', 'numberedList', 'todoList', '|',
                    'link', 'uploadImage', 'insertTable', 'blockQuote', '|',
                    'sourceEditing'
                ]
            } )
            .then( editor => {
                console.log( '編輯器初始化成功！', editor );
                // 您可以將 editor 實體儲存起來，方便後續取值
                window.myEditor = editor;
            } )
            .catch( error => {
                console.error( '編輯器初始化發生錯誤：', error );
            } );
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
        }
    },
});
const vm = app.mount('#app');
</script>
@endsection