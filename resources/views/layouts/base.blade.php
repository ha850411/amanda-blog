<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @yield('title')
    <link rel="stylesheet" href="{{ asset('css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/fontawesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body>
    {{-- header --}}
    <div id="app">
    @include('layouts/header')
    {{-- main content --}}
    @yield('content')
    {{-- footer --}}
    @include('layouts/footer')
    </div>

    <script src="{{ asset('js/vue/vue.global.min.js') }}"></script>
    <script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
    <script>
        const baseMixin = {
            data() {
                return {
                    isMenuOpen: false
                }
            },
            methods: {
                toggleMenu() {
                    this.isMenuOpen = !this.isMenuOpen;
                }
            }
        };
    </script>
    @yield('scripts')
</body>
</html>