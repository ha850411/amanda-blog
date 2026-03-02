@extends('layouts/base')

@section('title')
<title>Amanda | 探店 | 美食 | 生活 | 開箱</title>
@endsection

@section('content')
    <div class="container">
        <div class="col-12">
            <div class="row">
                <div class="col-md-8 col-12">
                    <div class="post my-4">
                        <div class="title_area">
                            <div class="title d-flex justify-content-between align-items-center">
                                <h4 class="m-0 py-3">LS樂食糖 | 百變多種口味的牛軋糖</h4>
                                <div class="time py-2 text-secondary">Jan 19 2026</div>
                            </div>
                            <div class="tag py-2 mb-4">
                                <a href="#" class="bg-secondary bg-gradient rounded-1 text-white p-2"><i
                                        class="fa-solid fa-tag"></i>宅配美食</a>
                                <a href="#" class="bg-secondary bg-gradient rounded-1 text-white p-2"><i
                                        class="fa-solid fa-tag"></i>糖果甜點</a>
                            </div>
                        </div>
                        <div class="article_area">
                            <img class="w-100" src="https://picsum.photos/500/400?random=1">
                        </div>
                        <p>年節將至適合送禮的樂食糖，外包裝十分的繽紛，淡淡的粉紅加粉綠的漸層色，配上各種口味的牛軋糖顏色，使人看起來心情更愉悅。</p>
                        <div class="article_area">
                            <img class="w-100" src="https://picsum.photos/500/400?random=2">
                        </div>
                        <p>除了有主要的牛軋糖，內容物也有年糖、Q糖餅以及咖啡包，不同於其他咖啡濾掛禮盒，樂食糖的咖啡包是整包的咖啡粉下去沖泡，可以使用冷萃法或熱泡法，兩種風味喝起來有些許的差異，熱泡法的咖啡豆酸味比較明顯，但是味道比較濃郁香醇，冷萃法的酸澀感比較低清爽順口，咖啡的沖泡方式也有貼心的附上說明小卡。
                        </p>
                        <div class="article_area">
                            <img class="w-100" src="https://picsum.photos/500/400?random=3">
                        </div>
                        <p>牛軋糖的口味很多，所以每一盒都會附上口味對照的小卡，讓消費者可以一眼就知道口味，由於口味眾多讓人都想嘗試，先吃了三種口味分別是吉利香橙、芋見草莓、抹茶好了莓，每種口味的內餡都十分豐富，用料實在、口味又多變，樂食糖致力研發多種口味，讓消費者對牛軋糖永遠保持新鮮感。
                        </p>
                    </div>
                </div>
                {{-- 關於我、最新文章、文章分類、網站瀏覽 --}}
                @include('layouts/about')
            </div>
        </div>
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