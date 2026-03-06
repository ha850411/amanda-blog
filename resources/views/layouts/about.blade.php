<div class="col-md-4 col-12">
    <div class="about d-flex flex-column align-items-center">
        <h5 class="text-white bg-dark bg-gradient rounded-1 title text-center p-2 my-4 w-100">
            關於我
        </h5>
        <div class="avatar rounded-circle overflow-hidden w-50 m-3">
            <img :src="base.about.data?.picture" class="w-100">
        </div>
        <p style="white-space: pre-line">@{{ base.about.data?.description || '' }}</p>
    </div>
    <div class="new_article text-left">
        <h5 class="text-white bg-dark bg-gradient rounded-1 title text-center p-2 my-4">
            最新文章
        </h5>
        <a href="#" class="text-dark">
            <h6 class="py-1 px-4 m-0">LS樂食糖 | 百變多種口味的牛軋糖</h6>
        </a>
        <a href="#" class="text-dark">
            <h6 class="py-1 px-4 m-0"><i class="fa-solid fa-key"></i>東光灶咖 ｜用料實在的巴斯克蛋糕</h6>
        </a>
    </div>
    <div class="d-md-block d-none category">
        <h5 class="text-white bg-dark bg-gradient rounded-1 title text-center p-2 my-4">
            文章分類
        </h5>
        <template v-for="tag in base.tags.data" :key="tag.id">
            <template v-if="!tag.children || tag.children.length === 0">
                <a :href="getTagUrl(tag.id)" class="menu text-dark">
                    <h6 class="py-1 px-4 m-0"><i class="fa-solid fa-arrows-to-dot"></i>@{{ tag.name }}</h6>
                </a>
            </template>
            <template v-else>
                <div class="menu">
                    <h6 class="py-1 px-4 m-0"><i class="fa-solid fa-arrows-to-dot"></i>@{{ tag.name }}</h6>
                    <li class="list-unstyled mt-2">
                        <template v-for="child in tag.children" :key="child.id">
                            <a :href="getTagUrl(child.id)" class="d-block text-secondary py-1 px-5">@{{ child.name }}</a>
                        </template>
                    </li>
                </div>
            </template>
        </template>
    </div>
    <div class="number text-center">
        <h5 class="text-white bg-dark bg-gradient rounded-1 title p-2 my-4">
            網站瀏覽
        </h5>
        <div class="date">今日瀏覽數：@{{ base.visit.data?.today || 0 }}</div>
        <div class="total">總瀏覽數：@{{ base.visit.data?.total || 0 }}</div>
    </div>
</div>