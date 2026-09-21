# Admin 首頁文章流量 Dashboard

`/admin` 使用 Cloudflare Web Analytics 的 RUM 瀏覽資料，顯示文章 PV、每日趨勢、熱門排行、瀏覽占比與搜尋。日期以 `Asia/Taipei` 計算，提供今天、近 7 天、近 30 天與最多 31 天的自訂區間。

## 正式環境設定

網站已由 Cloudflare 自動注入 Web Analytics beacon，毋須再加一份 JavaScript。建立專用 API Token，只給網站所屬帳戶的 **Account Analytics → Read** 權限，將機密放入正式站 `.env`：

```dotenv
CLOUDFLARE_ANALYTICS_API_TOKEN=你的唯讀Token
CLOUDFLARE_ACCOUNT_ID=abcff8b627068eccd2c3d48b43b7a6bf
CLOUDFLARE_ANALYTICS_HOSTNAME=amanda-blog.com
CLOUDFLARE_ANALYTICS_CACHE_SECONDS=0
CLOUDFLARE_WEB_ANALYTICS_SITE_TAG=24dc41967c4346ac8b5ddc0b7faf13a0
```

帳戶、網站與 hostname 已有此站的預設值。`SITE_TAG` 是 Web Analytics 網站識別碼，並非 beacon token；留空時依帳戶與 hostname 篩選。API Token 必須由環境提供，不可提交 Git 或寫進前端。

部署或更新 `.env` 後，在網站 PHP 容器內執行：

```sh
php artisan config:cache
php artisan view:clear
```

登入 `/admin` 後應看見資料。未設定 Token 時顯示設定提示；Cloudflare 拒絕查詢、逾時或資料不完整時顯示錯誤，不會當成零流量。Token 更換後快取會使用新的憑證範圍。

## 統計範圍

- 使用 `rumPageloadEventsAdaptiveGroups.count`，Cloudflare 已完成抽樣估算，不再乘上 `sampleInterval`。
- 只計算資料庫目前存在的 `/article/{id}` 與尾端有 `/` 的文章網址；不包含首頁、後台、圖片、API 或已刪除文章。隱藏及密碼文章保留於排行，密碼頁瀏覽不表示讀者已解鎖內容。
- PV 是頁面瀏覽次數，不是獨立訪客或廣告點擊率。JavaScript 封鎖、網路狀況、資料保留期及 Cloudflare 抽樣可能影響結果。
- UTC 小時資料換算為台灣日期；今天截至查詢當下。文章排行與每日趨勢各自彙整，抽樣時加總可能略有差異。
- 預設不快取：開啟頁面或按「查詢最新資料」時，直接向 Cloudflare 查詢。既有快取也不會被讀取，API 回應帶有 `private, no-store`。這不會消除 Cloudflare 收集與處理資料的延遲，頁面不會自動輪詢。
- 如需降低查詢頻率，可設定 `CLOUDFLARE_ANALYTICS_CACHE_SECONDS` 為正秒數；畫面會顯示實際快取秒數。API 僅供登入的後台管理者使用，每分鐘最多 30 次。

官方文件：[Web Analytics](https://developers.cloudflare.com/web-analytics/about/)、[Analytics Token](https://developers.cloudflare.com/analytics/graphql-api/getting-started/authentication/api-token-auth/)、[GraphQL 查詢限制](https://developers.cloudflare.com/analytics/graphql-api/limits/)。
