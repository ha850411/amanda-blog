@extends('admin/layouts/base')

@section('title')
<title>後台-首頁</title>
@endsection

@section('content')
    @include('admin/layouts/menu')

    <div class="main">
        @include('admin/layouts/header')
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