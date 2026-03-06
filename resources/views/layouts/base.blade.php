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
        <template v-if="!base.inital">
            <div class="d-flex justify-content-center align-items-center" style="height: 100vh;">
                <div class="spinner-border text-secondary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        </template>
        <template v-else>
            @include('layouts/header')
            {{-- main content --}}
            @yield('content')
            {{-- footer --}}
            @include('layouts/footer')
        </template>
    </div>

    <script src="{{ asset('js/vue/vue.global.min.js') }}"></script>
    <script src="{{ asset('js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('js/axios/axios.min.js') }}"></script>
    <script src="{{ asset('js/sweetalert2/sweetalert2.min.js') }}"></script>
    <script>
        const baseMixin = {
            data() {
                return {
                    base: {
                        isMenuOpen: false,
                        inital: false,
                        about: {
                            route: '{{ route('api.about.index') }}',
                            loading: false,
                            data: null,
                        },
                        tags: {
                            route: '{{ route('api.tag.index') }}',
                            loading: false,
                            data: [],
                        },
                        socials: {
                            route: '{{ route('api.social.index') }}',
                            loading: false,
                            data: [],
                        },
                        visit: {
                            route: '{{ route('api.visit.index') }}',
                            store: '{{ route('api.visit.store') }}',
                            data: null
                        },
                        web: {
                            tag: '{{ route("index") }}',
                        },
                    }
                }
            },
            mounted() {
                Promise.all([
                    this.getAbout(),
                    this.getTags(),
                    this.getSocials(),
                    this.getVisit(),
                ]).then(() => {     
                    this.base.inital = true;
                });
                this.addVisit();
            },
            methods: {
                toggleMenu() {
                    this.base.isMenuOpen = !this.base.isMenuOpen;
                },
                async getAbout() {
                    try {
                        const res = await axios.get(this.base.about.route);
                        this.base.about.data = res.data.data;
                    } catch (error) {
                        console.error(error);
                    }
                },
                async getTags() {
                    try {
                        const res = await axios.get(this.base.tags.route);
                        this.base.tags.data = res.data.data;
                    } catch (error) {
                        console.error(error);
                    }
                },
                async getSocials() {
                    try {
                        const res = await axios.get(this.base.socials.route);
                        this.base.socials.data = res.data.data;
                    } catch (error) {
                        console.error(error);
                    }
                },
                async getVisit() {
                    try {
                        const res = await axios.get(this.base.visit.route);
                        this.base.visit.data = res.data.data;
                    } catch (error) {
                        console.error(error);
                    }
                },
                async addVisit() {
                    try {
                        await axios.post(this.base.visit.store);
                    } catch (error) {
                        console.error(error);
                    }
                },
                getTagUrl(tagId) {
                    return `${this.base.web.tag}?tag=${tagId}`;
                }
            }
        };
    </script>
    @yield('scripts')
</body>
</html>