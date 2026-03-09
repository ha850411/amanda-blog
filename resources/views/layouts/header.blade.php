<div class="top_header bg-dark bg-gradient sticky-top">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="nav_toggle text-white d-md-none d-block" @click="toggleMenu"
            :class="{ show_icon: base.isMenuOpen }">
            <i class="fa-solid fa-bars nav_burger"></i>
            <i class="fa-solid fa-x nav_close"></i>
        </div>
        <ul class="nav_menu" :class="{ show_menu: base.isMenuOpen }">
            <li v-for="item in base.tags.data" :key="item.id">
                <a :href="getTagUrl(item.id)" class="text-white text-center me-1"
                    :class="{ active: base.tagId == item.id || item.children.some(child => child.id == base.tagId) }">
                    <span>@{{ item.name }}</span>
                    <i v-if="item.children && item.children.length > 0" class="fa-solid fa-caret-down"></i>
                </a>
                <template v-if="item.children && item.children.length > 0">
                    <ul class="dropdown">
                        <li v-for="child in item.children" :key="child.id">
                            <a :href="getTagUrl(child.id)" class="text-center"
                                :class="{ active: base.tagId == child.id }">@{{ child.name }}</a>
                        </li>
                    </ul>
                </template>
            </li>
        </ul>
        <div class="social fs-4">
            <template v-for="social in base.socials.data" :key="social.id">
                <template v-if="social.status === 1">
                    <a :href="social.url" class="text-white me-1" target="_blank">
                        <i :class="social.icon"></i>
                    </a>
                </template>
            </template>
        </div>
    </div>
</div>
<div class="header text-center py-5 px-2">
    <a href="{{ route('index') }}" class="title text-dark">
        <h2>@{{ base.about.data?.title || '' }}</h2>
    </a>
    <p style="white-space: pre-line" v-html="base.about.data?.sub_title || ''"></p>
</div>