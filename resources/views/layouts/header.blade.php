<div class="top_header bg-dark bg-gradient sticky-top">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="nav_toggle text-white d-md-none d-block" @click="toggleMenu"
            :class="{ show_icon: base.isMenuOpen }" role="button" tabindex="0" @keydown.enter="toggleMenu" aria-label="切換選單" :aria-expanded="base.isMenuOpen">
            <i class="fa-solid fa-bars nav_burger"></i>
            <i class="fa-solid fa-x nav_close"></i>
        </div>
        <ul class="nav_menu" :class="{ show_menu: base.isMenuOpen }">
            @foreach ($siteTags as $item)
                <li v-pre>
                    <a href="{{ route('tag', ['tagId' => $item->id]) }}" class="text-white text-center me-1 {{ ($tagId ?? null) == $item->id || $item->children->contains('id', $tagId ?? null) ? 'active' : '' }}">
                        <span>{{ $item->name }}</span>
                        @if ($item->children->isNotEmpty())
                            <i class="fa-solid fa-caret-down"></i>
                        @endif
                    </a>
                    @if ($item->children->isNotEmpty())
                        <ul class="dropdown">
                            @foreach ($item->children as $child)
                                <li><a href="{{ route('tag', ['tagId' => $child->id]) }}" class="text-center {{ ($tagId ?? null) == $child->id ? 'active' : '' }}">{{ $child->name }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="social fs-4" v-pre>
            @foreach ($siteSocials as $social)
                <a href="{{ $social->url }}" class="text-white me-1" target="_blank" rel="noopener noreferrer" aria-label="社群連結">
                    <i class="{{ $social->icon }}"></i>
                </a>
            @endforeach
        </div>
    </div>
</div>
<div class="header text-center py-5 px-2" v-pre>
    <a href="{{ route('index') }}" class="title text-dark">
        <h2>{{ $siteAbout?->title ?? '' }}</h2>
    </a>
    <p style="white-space: pre-line">{!! $siteAbout?->sub_title ?? '' !!}</p>
</div>
<noscript><style>.category.d-none { display: block !important; } .nav_toggle { display: none !important; }</style></noscript>
