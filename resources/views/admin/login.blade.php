@extends('admin/layouts/base')

@section('title')
<title>後台-登入</title>
@endsection

@section('content')
<div class="wrapper p-4 col-md-4 col-12 rounded-2">
    <h4 class="text-center text-secondary mb-3">登入</h4>
    <div class="mb-3">
        <label for="exampleFormControlInput1" class="form-label">帳號</label>
        <input type="email" class="form-control" id="exampleFormControlInput1" placeholder="請輸入您的帳號">
    </div>
    <div class="mb-3">
        <label for="exampleFormControlTextarea1" class="form-label">密碼</label>
        <input type="password" class="form-control" id="exampleFormControlInput1" placeholder="請輸入您的密碼">
    </div>
    <a href="index.html"><button type="button" class="btn btn-primary mt-2 w-100">確認</button></a>
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
    },
});
const vm = app.mount('#app');
</script>
@endsection