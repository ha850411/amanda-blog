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
    Image, ImageUpload, ImageToolbar, ImageCaption, ImageStyle, ImageResize, LinkImage, SimpleUploadAdapter,
    SourceEditing
} = CKEDITOR;

class InitCKEditor {
    static init(editorId) {
        return ClassicEditor
            .create(document.querySelector(editorId), {
                licenseKey: 'GPL',
                language: 'zh',
                plugins: [
                    Essentials, Heading, Bold, Italic, Underline, Strikethrough,
                    FontColor, FontBackgroundColor, Link, List, TodoList,
                    Alignment, BlockQuote,
                    Image, ImageUpload, ImageToolbar, ImageCaption, ImageStyle, ImageResize, LinkImage, SimpleUploadAdapter,
                    SourceEditing
                ],
                toolbar: [
                    'undo', 'redo', '|',
                    'heading', '|',
                    'bold', 'italic', 'underline', 'strikethrough', '|',
                    'fontColor', 'fontBackgroundColor', '|',
                    'alignment', 'bulletedList', 'numberedList', 'todoList', '|',
                    'link', 'uploadImage', 'blockQuote', '|',
                    'sourceEditing'
                ],
                simpleUpload: {
                    uploadUrl: '/api/image/upload',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    }
                },
                image: {
                    toolbar: [
                        'imageStyle:inline',
                        'imageStyle:wrapText',
                        'imageStyle:breakText',
                        '|',
                        'toggleImageCaption',
                        'imageTextAlternative'
                    ]
                }
            })
            .then(editor => {
                window.myEditor = editor;
                return editor;
            })
            .catch(error => {
                console.error('編輯器初始化發生錯誤：', error);
                throw error;
            });
    }
}