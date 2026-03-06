<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @yield('title')
    <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fontawesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    @yield('style')
</head>
<body class="{{ $bodyClass ?? '' }}">
    <div id="app">
        @yield('content')
    </div>

    <script src="{{ asset('js/vue/vue.global.min.js') }}"></script>
    <script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('js/axios/axios.min.js') }}"></script>
    <script src="{{ asset('js/sweetalert2/sweetalert2.min.js') }}"></script>
    <script>
        axios.defaults.headers.common['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
        axios.defaults.withCredentials = true;

        const baseMixin = {
            data() {
                return {
                    isNavActive: false,
                }
            },
            mounted() {
                this.isNavActive = window.innerWidth < 767;
                this._resizeHandler = () => {
                    this.isNavActive = window.innerWidth < 767;
                };
                window.addEventListener('resize', this._resizeHandler);
            },
            beforeUnmount() {
                window.removeEventListener('resize', this._resizeHandler);
            },
            watch: {
                isNavActive(val) {
                    const navigation = document.querySelector('.navigation');
                    const main = document.querySelector('.main');
                    if (navigation) navigation.classList.toggle('active', val);
                    if (main) main.classList.toggle('active', val);
                },
            },
            methods: {
                modal(modalRef, action = 'toggle') {
                    const el = this.$refs[modalRef];
                    if (!el) return;
                    if (action === 'toggle') {
                        el.style.display = (el.style.display === 'block') ? 'none' : 'block';
                    } else if (action === 'show') {
                        el.style.display = 'block';
                    } else if (action === 'hide') {
                        el.style.display = 'none';
                    }
                },
                toggleMenu() {
                    this.isNavActive = !this.isNavActive;
                },
            }
        };
    </script>
    @yield('scripts')
</body>
</html>