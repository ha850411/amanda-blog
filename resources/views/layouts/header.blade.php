<div class="top_header bg-dark bg-gradient sticky-top">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="nav_toggle text-white d-md-none d-block" @click="toggleMenu" :class="{ show_icon: isMenuOpen }">
            <i class="fa-solid fa-bars nav_burger"></i>
            <i class="fa-solid fa-x nav_close"></i>
        </div>
        <ul class="nav_menu" :class="{ show_menu: isMenuOpen }">
            <li>
                <a href="#" class="text-white text-center me-1">台中美食<i class="fa-solid fa-caret-down"></i></a>
                <ul class="dropdown">
                    <li><a href="#" class="text-center">牛排館</a></li>
                    <li><a href="#" class="text-center">火鍋店</a></li>
                    <li><a href="#" class="text-center">咖啡店</a></li>
                    <li><a href="#" class="text-center">早午餐</a></li>
                </ul>
            </li>
            <li><a href="#" class="text-white text-center me-1">宅配商品</a></li>
            <li><a href="#" class="text-white text-center me-1">采耳體驗</a></li>
            <li><a href="#" class="text-white text-center me-1">按摩體驗</a></li>
        </ul>
        <div class="social fs-4">
            <a href="#" class="text-white me-1"><i class="fa-brands fa-youtube"></i></a>
            <a href="#" class="text-white me-1"><i class="fa-brands fa-facebook"></i></a>
            <a href="#" class="text-white me-1"><i class="fa-brands fa-instagram"></i></a>
        </div>
    </div>
</div>
<div class="header text-center py-5 px-2">
    <a href="{{ route('index') }}" class="title text-dark">
        <h2>Amanda | 探店 | 美食 | 生活 | 開箱</h2>
    </a>
    <p>🏠探店🍜美食🕊️生活📦開箱 台中生活圈跑跳🌇 </br>💌合作邀約歡迎 summer.hung222@gmail.com</p>
</div>