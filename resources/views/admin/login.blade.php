@extends('admin/layouts/base')

@section('title')
<title>後台-登入</title>
@endsection

@section('content')
<div class="wrapper p-4 col-md-4 col-12 rounded-2">
    <h4 class="text-center text-secondary mb-3">登入</h4>
    <div v-if="errorMessage" class="alert alert-danger py-2" role="alert">
        @{{ errorMessage }}
    </div>
    <div class="mb-3">
        <label for="username" class="form-label">帳號</label>
        <input type="text" class="form-control" id="username" v-model="form.username"
               placeholder="請輸入您的帳號" @keyup.enter="handleLogin">
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">密碼</label>
        <input type="password" class="form-control" id="password" v-model="form.password"
               placeholder="請輸入您的密碼" @keyup.enter="handleLogin">
    </div>
    <button type="button" class="btn btn-primary mt-2 w-100" @click="handleLogin" :disabled="isLoading">
        <span v-if="isLoading" class="spinner-border spinner-border-sm me-1" role="status"></span>
        @{{ isLoading ? '登入中...' : '確認' }}
    </button>
</div>
@endsection

@section('scripts')
<script>
const app = Vue.createApp({
    mixins: [baseMixin],
    data() {
        return {
            form: {
                username: '',
                password: '',
            },
            errorMessage: '',
            isLoading: false,
        }
    },
    mounted() {
    },
    methods: {
        async handleLogin() {
            if (this.isLoading) return;

            this.errorMessage = '';

            if (!this.form.username || !this.form.password) {
                this.errorMessage = '請輸入帳號和密碼';
                return;
            }

            this.isLoading = true;

            try {
                const { data } = await axios.post('/api/admin/login', this.form);

                if (data.success) {
                    window.location.href = data.redirect;
                } else {
                    this.errorMessage = data.message || '登入失敗';
                }
            } catch (error) {
                const msg = error.response?.data?.message;
                this.errorMessage = msg || '系統錯誤，請稍後再試';
            } finally {
                this.isLoading = false;
            }
        },
    },
});
const vm = app.mount('#app');
</script>
@endsection