<div class="navigation">
    <ul>
        <li class="{{ $active == 'index' ? 'active' : '' }}">
            <a href="{{ route('admin.index') }}">
                <span class="icon"><i class="fa-solid fa-house"></i></span>
                <span class="title">首頁</span>
            </a>
        </li>
        <li class="{{ $active == 'about' ? 'active' : '' }}">
            <a href="{{ route('admin.about') }}">
                <span class="icon"><i class="fa-solid fa-bars-progress"></i></span>
                <span class="title">個人資料</span>
            </a>
        </li>
        <li class="{{ $active == 'tag' ? 'active' : '' }}">
            <a href="{{ route('admin.tag') }}">
                <span class="icon"><i class="fa-solid fa-bars-progress"></i></span>
                <span class="title">標籤管理</span>
            </a>
        </li>
        <li class="{{ $active == 'article' ? 'active' : '' }}">
            <a href="{{ route('admin.article') }}">
                <span class="icon"><i class="fa-solid fa-scroll"></i></span>
                <span class="title">文章管理</span>
            </a>
        </li>
        <li class="{{ $active == 'social' ? 'active' : '' }}">
            <a href="{{ route('admin.social') }}">
                <span class="icon"><i class="fa-solid fa-star"></i></span>
                <span class="title">社群管理</span>
            </a>
        </li>
        <li>
            <a href="{{ route('admin.logout') }}">
                <span class="icon"><i class="fa-solid fa-right-from-bracket"></i></span>
                <span class="title">登出</span>
            </a>
        </li>
    </ul>
</div>