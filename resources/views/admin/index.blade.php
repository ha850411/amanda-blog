@extends('admin/layouts/base')

@section('title')
<title>後台-文章流量 Dashboard</title>
@endsection

@section('style')
<link rel="stylesheet" href="{{ asset('css/admin-analytics.css') }}">
@endsection

@section('content')
    @include('admin/layouts/menu')

    <div class="main analytics-main">
        @include('admin/layouts/header')
        <main class="analytics-dashboard" v-cloak>
            <header class="analytics-heading">
                <div><div class="analytics-eyebrow">AMANDA / ANALYTICS</div><h1>文章流量總覽</h1><p>看看哪些分享，正在被更多人閱讀。</p></div>
                <div class="analytics-source"><span class="source-dot" :class="{ connected: dashboard }"></span>Cloudflare Web Analytics</div>
            </header>
            <form class="analytics-filters" @submit.prevent="loadDashboard">
                <div class="analytics-presets" aria-label="快速選擇日期">
                    <button v-for="option in presets" :key="option.days" type="button" class="btn btn-sm" :class="preset === option.days ? 'btn-dark' : 'btn-outline-secondary'" :aria-pressed="preset === option.days" :disabled="loading" @click="selectPreset(option.days)">@{{ option.label }}</button>
                </div>
                <div class="analytics-date-inputs">
                    <label>開始日期<input type="date" class="form-control form-control-sm" v-model="start" :max="today" required @change="preset = null"></label>
                    <label>結束日期<input type="date" class="form-control form-control-sm" v-model="end" :min="start" :max="today" required @change="preset = null"></label>
                    <button class="btn btn-dark btn-sm" type="submit" :disabled="loading">@{{ loading ? '讀取中…' : '查詢最新資料' }}</button>
                </div>
            </form>
            <div v-if="loading" class="analytics-state" role="status" aria-live="polite"><div class="spinner-border text-secondary mb-3" aria-hidden="true"></div><h2>正在整理文章流量</h2><p>正在取得所選日期的 Cloudflare 瀏覽資料。</p></div>
            <div v-else-if="state === 'not_configured'" class="analytics-state analytics-setup" role="status">
                <div class="setup-icon"><i class="fa-solid fa-chart-column" aria-hidden="true"></i></div>
                <h2>連接 Cloudflare，開始看見文章表現</h2><p>網站的分析程式已在收集資料；完成唯讀 API 串接後，這裡就會顯示文章排行與趨勢。</p>
                <ol><li>在 Cloudflare 建立僅含 <strong>Account Analytics → Read</strong> 權限的 API Token，限定網站所屬帳戶。</li><li>將 <code>CLOUDFLARE_ANALYTICS_API_TOKEN</code> 與 <code>CLOUDFLARE_ACCOUNT_ID</code> 設定到正式環境，更新 Laravel 設定快取。</li><li>回到本頁重新查詢。Token 僅保存在伺服器，不會顯示於網頁。</li></ol>
                <a href="https://developers.cloudflare.com/analytics/graphql-api/getting-started/authentication/api-token-auth/" target="_blank" rel="noopener" class="btn btn-outline-dark btn-sm">Cloudflare 設定說明 ↗</a>
            </div>
            <div v-else-if="error" class="analytics-state" role="alert"><div class="setup-icon"><i class="fa-solid fa-plug-circle-exclamation" aria-hidden="true"></i></div><h2>目前無法顯示流量資料</h2><p>@{{ error }}</p><button type="button" class="btn btn-outline-dark btn-sm" @click="loadDashboard">重新查詢</button></div>
            <template v-else-if="dashboard">
                <div class="analytics-period"><span>@{{ dashboard.start }} — @{{ dashboard.end }} <span class="text-secondary ms-1">台灣時間</span></span><span class="text-secondary">查詢時間 @{{ formatTime(dashboard.fetched_at) }} · @{{ dashboard.cache_seconds > 0 ? '快取 ' + dashboard.cache_seconds + ' 秒' : '每次查詢直接取得 Cloudflare 資料' }}<br>Cloudflare 收集與處理資料仍可能延遲</span></div>
                <section class="analytics-metrics" aria-label="流量摘要">
                    <article class="metric-card metric-primary"><div class="metric-label">文章瀏覽次數 <span>PV</span></div><div class="metric-number">@{{ number(dashboard.summary.page_views) }}</div><div class="metric-caption">所選期間所有文章的瀏覽總和</div></article>
                    <article class="metric-card"><div class="metric-label">有瀏覽的文章</div><div class="metric-number">@{{ number(dashboard.summary.viewed_articles) }}<small>/ @{{ number(dashboard.summary.total_articles) }}</small></div><div class="metric-caption">目前文章中有記錄到瀏覽的篇數</div></article>
                    <article class="metric-card"><div class="metric-label">平均每日瀏覽</div><div class="metric-number">@{{ number(dashboard.summary.average_daily_views) }}</div><div class="metric-caption">依所選天數平均，今天僅含目前資料</div></article>
                    <article class="metric-card"><div class="metric-label">最熱門文章</div><template v-if="topArticle"><div class="metric-number">@{{ number(topArticle.page_views) }}<small>次</small></div><a class="metric-top-title" :href="topArticle.url" target="_blank" rel="noopener">@{{ topArticle.title }}</a></template><template v-else><div class="metric-number">—</div><div class="metric-caption">此期間尚未記錄到文章瀏覽</div></template></article>
                </section>
                <section class="analytics-panel" aria-labelledby="trend-title">
                    <div class="panel-heading"><div><h2 id="trend-title">每日閱讀趨勢</h2><p>文章瀏覽次數 · 台灣時間 00:00 至 24:00</p></div><div class="trend-selected" aria-live="polite"><span>@{{ activeDay ? activeDay.date : '移至長條查看每日數據' }}</span><strong v-if="activeDay">@{{ number(activeDay.page_views) }} <small>次</small></strong></div></div>
                    <div v-if="dashboard.summary.page_views === 0" class="analytics-empty">此期間尚未記錄到文章瀏覽。可切換日期，或確認 Cloudflare 是否持續收集資料。</div>
                    <div v-else class="trend-scroll"><div class="trend-chart">
                        <div class="trend-scale"><span>@{{ number(maxDaily) }}</span><span>@{{ number(Math.round(maxDaily / 2)) }}</span><span>0</span></div>
                        <div class="trend-grid" :style="{ gridTemplateColumns: 'repeat(' + dashboard.daily.length + ', minmax(12px, 1fr))' }"><div class="trend-column" v-for="day in dashboard.daily" :key="day.date"><button type="button" class="trend-bar" :class="{ 'is-selected': activeDay?.date === day.date }" :style="{ height: Math.max(day.page_views ? 2 : 0.5, day.page_views / maxDaily * 100) + '%' }" :aria-label="day.date + '：' + number(day.page_views) + ' 次瀏覽'" :title="day.date + '：' + number(day.page_views) + ' 次瀏覽'" @mouseenter="activeDay = day" @focus="activeDay = day" @click="activeDay = day"></button></div></div>
                        <div class="trend-dates"><span>@{{ dashboard.start.slice(5).replace('-', '/') }}</span><span>@{{ dashboard.end.slice(5).replace('-', '/') }}</span></div>
                    </div></div>
                </section>
                <section class="analytics-panel" aria-labelledby="ranking-title">
                    <div class="panel-heading ranking-heading"><div><h2 id="ranking-title">文章瀏覽排行</h2><p>依瀏覽次數排序 · 占比為該文章占期間文章總瀏覽的比例</p></div><label class="article-search"><span class="visually-hidden">搜尋文章標題</span><input type="search" class="form-control form-control-sm" v-model="search" placeholder="搜尋文章標題…" @input="page = 1"></label></div>
                    <div class="table-responsive"><table class="table analytics-table"><thead><tr><th scope="col" class="rank-cell">排行</th><th scope="col">文章</th><th scope="col" class="text-end">瀏覽次數</th><th scope="col" class="share-cell">瀏覽占比</th><th scope="col">操作</th></tr></thead><tbody>
                        <tr v-for="article in paginatedArticles" :key="article.id"><td class="rank-cell"><span :class="{ 'top-rank': article.rank <= 3 && article.page_views > 0 }">@{{ article.rank }}</span></td><td class="article-title-cell"><a :href="article.url" target="_blank" rel="noopener">@{{ article.title }}</a><div class="article-status">@{{ statusLabel(article.status) }}</div></td><td class="text-end view-count">@{{ number(article.page_views) }}</td><td class="share-cell"><div class="share-label">@{{ article.share.toFixed(1) }}%</div><div class="share-track"><span :style="{ width: article.share + '%' }"></span></div></td><td><a class="btn btn-sm btn-outline-secondary" :href="article.edit_url">編輯</a></td></tr>
                        <tr v-if="filteredArticles.length === 0"><td colspan="5" class="text-center py-4 text-secondary">@{{ search ? '找不到符合的文章' : '目前沒有文章' }}</td></tr>
                    </tbody></table></div>
                    <div class="analytics-pagination"><span>共 @{{ number(filteredArticles.length) }} 篇文章</span><div><button type="button" class="btn btn-sm btn-outline-secondary" :disabled="page <= 1" @click="page--">上一頁</button><span class="mx-3">@{{ page }} / @{{ totalPages }}</span><button type="button" class="btn btn-sm btn-outline-secondary" :disabled="page >= totalPages" @click="page++">下一頁</button></div></div>
                </section>
                <footer class="analytics-note"><p><strong>@{{ dashboard.sampled ? '此期間包含 Cloudflare 抽樣估算資料。' : '資料來源：Cloudflare Web Analytics。' }}</strong> 瀏覽次數不等於不重複訪客；瀏覽占比也不是廣告點擊率。</p><p>只統計目前文章頁面；不含首頁、圖片、API 與已刪除文章。瀏覽器封鎖分析程式、網路狀況及資料延遲可能造成漏計。密碼文章的瀏覽不代表已解鎖閱讀。</p><p>每日趨勢與文章排行分別由 Cloudflare 彙整，抽樣時加總可能略有差異。資料截至 @{{ formatTime(dashboard.through) }}。</p></footer>
            </template>
        </main>
        <noscript><div class="p-4">請開啟 JavaScript 以查看文章流量 Dashboard。</div></noscript>
    </div>
@endsection

@section('scripts')
<script>
const app = Vue.createApp({
    mixins: [baseMixin],
    data() {
        return {
            today: @json($analyticsToday), start: @json($analyticsStart), end: @json($analyticsToday),
            preset: 7, presets: [{ days: 1, label: '今天' }, { days: 7, label: '近 7 天' }, { days: 30, label: '近 30 天' }],
            loading: true, state: '', error: '', dashboard: null, activeDay: null,
            search: '', page: 1, perPage: 10, requestId: 0,
        };
    },
    mounted() {
        this.loadDashboard();
    },
    computed: {
        topArticle() { return this.dashboard?.articles.find(article => article.page_views > 0) || null; },
        maxDaily() { return Math.max(1, ...this.dashboard.daily.map(day => day.page_views)); },
        filteredArticles() {
            const query = this.search.trim().toLocaleLowerCase();
            return (this.dashboard?.articles || []).map((article, index) => ({ ...article, rank: index + 1 }))
                .filter(article => article.title.toLocaleLowerCase().includes(query));
        },
        totalPages() { return Math.max(1, Math.ceil(this.filteredArticles.length / this.perPage)); },
        paginatedArticles() { return this.filteredArticles.slice((this.page - 1) * this.perPage, this.page * this.perPage); },
    },
    methods: {
        number(value) { return Number(value || 0).toLocaleString('zh-TW', { maximumFractionDigits: 1 }); },
        formatTime(value) { return new Date(value).toLocaleString('zh-TW', { timeZone: 'Asia/Taipei', hour12: false }); },
        statusLabel(status) { return ({ 1: '公開', 2: '密碼保護' })[status] || '隱藏'; },
        selectPreset(days) {
            const start = new Date(this.today + 'T00:00:00Z');
            start.setUTCDate(start.getUTCDate() - days + 1);
            this.start = start.toISOString().slice(0, 10); this.end = this.today; this.preset = days;
            this.loadDashboard();
        },
        async loadDashboard() {
            const requestId = ++this.requestId;
            this.error = ''; this.state = ''; this.dashboard = null; this.activeDay = null; this.page = 1;
            const days = (Date.parse(this.end) - Date.parse(this.start)) / 86400000 + 1;
            if (!this.start || !this.end || !Number.isFinite(days) || days < 1 || days > 31 || this.end > this.today) {
                this.error = '請選擇有效日期；結束日不可晚於今天，每次最多查詢 31 天。'; this.loading = false; return;
            }
            this.loading = true;
            try {
                const response = await axios.get(@json(route('api.admin.analytics.articles')), { params: { start: this.start, end: this.end }, timeout: 25000 });
                if (requestId !== this.requestId) return;
                this.state = response.data.status;
                if (this.state === 'ready') this.dashboard = response.data.data;
                else if (this.state !== 'not_configured') this.error = '未取得完整流量資料，請稍後重試。';
            } catch (error) {
                if (requestId !== this.requestId) return;
                const status = error.response?.status;
                if (status === 401) this.error = '登入已逾時，請重新登入後台。';
                else if (status === 429) this.error = '查詢次數較多，請稍等一分鐘再試。';
                else if (status === 422) this.error = Object.values(error.response.data.errors || {}).flat().join(' ') || '請確認查詢日期。';
                else this.error = error.response?.data?.message || '無法連接流量服務，請稍後重試。';
            } finally { if (requestId === this.requestId) this.loading = false; }
        },
    },
});
const vm = app.mount('#app');
</script>
@endsection
