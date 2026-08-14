# Amanda's Blog

# 使用技術
![Laravel 12](https://img.shields.io/badge/Laravel_12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![Vue 3](https://img.shields.io/badge/Vue_3-35495E?style=for-the-badge&logo=vue.js&logoColor=4FC08D)
![MySQL 8.0](https://img.shields.io/badge/MySQL_8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Redis 7.0](https://img.shields.io/badge/Redis_7.0-DD0031?style=for-the-badge&logo=redis&logoColor=white)

# 前置作業
本機環境需安裝 docker、docker-compose
- docker: https://www.docker.com/products/docker-desktop/
- docker-compose: https://docs.docker.com/compose/install/

# 安裝
啟動 docker
```
/workspace/amanda-blog
> make up
```
安裝 composer 套件
```
/workspace/amanda-blog
> make composer-install
```

# 測試
建立測試資料庫
```
/workspace/amanda-blog
> make ensure-testing-db
```
執行測試
```
/workspace/amanda-blog
> make test
```

# 訪問專案 url
- 前台: http://localhost:8091
- 後台: http://localhost:8091/admin

# Runtime image

透過 `make deploy-up` 部署時，Runtime image tag 會依 `.docker/Dockerfile`、PHP base image 與 Composer base image自動產生；相同建置內容會沿用既有 image，建置內容有變更時則會自動使用新 tag。可執行 `make runtime-tag` 查看目前 tag，不需在 `.env` 手動維護 `RUNTIME_IMAGE_TAG`。

CI 必須從 secret store 注入 `GHCR_TOKEN`（GitHub classic PAT，具備 `write:packages`），不得將 token 寫入 repository 或 `.env`。`make deploy-up` 會先登入 GHCR，檢查 fingerprint image 是否存在；不存在時才建置並推送至 `ghcr.io/ha850411/amanda-blog-runtime`，同時將 BuildKit layer cache 保存於 `buildcache-php8.4` tag，完成後才啟動新容器。

Jenkins 使用 Credentials Binding 將 Secret text credential 綁定為 `GHCR_TOKEN`；若使用 Pipeline，可用 `withCredentials([string(credentialsId: 'ghcr-token', variable: 'GHCR_TOKEN')])` 包住原本的部署指令。部署主機上的 Docker 登入狀態也會讓後續 private image pull 使用同一份憑證。

# LINE 賽程 Bot

Webhook URL 設為 `https://你的網域/api/line/webhook`，並在 `.env` 設定：

```env
LINE_CHANNEL_SECRET=你的_Messaging_API_Channel_Secret
LINE_CHANNEL_ACCESS_TOKEN=你的_Channel_Access_Token
LINE_REPLY_TOKEN_SAFE_WINDOW_SECONDS=45
# 圖片必須位於 LINE 可讀取的公開 HTTPS 網址；正式環境建議使用 s3
LINE_SCHEDULE_IMAGE_DISK=s3
LINE_SCHEDULE_IMAGE_FONT=/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc
LINE_SCHEDULE_IMAGE_RETENTION_DAYS=7
```

輸入 `!help` 才會顯示使用說明；一般訊息與無效指令不會回覆。指令格式為 `!lol 08/11`、`!val 今天`、`!cs 明天`，日期支援 `MM/DD`、今天、明天與後天，預設只查 S/A Tier。可加上 `tier=s`、`tier=a,b`、`tier=all`、`limit=5`、`team=G2` 等參數，例如 `!cs 08/11 tier=s limit=5`。賽程來自 bo3.gg，顯示時間使用 `BO3_TIMEZONE`（預設為 Asia/Taipei）。LoL 賽程會另外透過 `BO3_API_URL` 取得最近五次 Head to Head 的系列賽勝場、小局總比分，以及每次交手的日期、BO 賽制、比分與勝方；逐場資訊會列在賽程圖中各對局的右側。資料暫時無法取得時仍會正常顯示賽程。

LINE webhook 會先記錄事件並快速回傳 HTTP 200，再由 queue worker 處理外部 API、圖片與 LINE 回覆；正式環境需執行 `php artisan migrate --force`，並保持 `php artisan queue:work --tries=3 --backoff=2 --timeout=300` 常駐。queue connection 的 `retry_after` 必須大於 300 秒（預設 330 秒），避免同一工作被重複執行。若事件在 queue 等待與背景處理的總時間超過 `LINE_REPLY_TOKEN_SAFE_WINDOW_SECONDS`（預設 45 秒），Bot 會跳過即將失效的 reply token，直接以事件來源 ID 改用 push message；若 Reply API 提早拒絕 token，也會 fallback 成 push。queue dispatch 失敗時 webhook 會回傳 HTTP 500，讓已開啟 webhook redelivery 的 LINE channel 能重新投遞事件。

查到賽程時會回覆一張可點擊放大的賽程圖，並另外用一則文字訊息提供符合遊戲、日期與 Tier 條件的 bo3.gg 總覽連結，不會再為每場賽事附上個別連結。圖片會顯示賽事名稱與 BO 賽制，並以 Odds-API.io 的日期、開賽時間與雙方隊名匹配盤口。設定 `ODDS_API_KEY` 後，會依 `ODDS_API_BOOKMAKER_PRIORITY` 選擇同一家莊家的完整雙邊 ML（預設 Stake 優先、Bet365 備援），不會混搭兩家盤口；`ODDS_API_BOOKMAKERS` 留空時自動使用帳號已選擇的莊家。若所選莊家沒有完整雙邊 ML，會改用 bo3.gg 該場賽事提供的同一家莊家獨贏盤，兩邊都沒有時才顯示「暫無盤口」。圖片會直接寫入 `LINE_SCHEDULE_IMAGE_DISK`（預設固定為 `s3`），不會儲存在主機的 `public/storage`；S3 的 `Storage::url()` 必須產生公開 HTTPS 網址。Scheduler 每天 03:30（台灣時間）只清理 `line-schedules/` 下超過 `LINE_SCHEDULE_IMAGE_RETENTION_DAYS`（預設 7 天）的物件，因此 S3 IAM 除了上傳與讀取外，也需要 `ListBucket` 與 `DeleteObject` 權限。若圖片產生或儲存失敗，Bot 會降級成文字回覆，且只保留一個總覽連結。

Webhook 執行紀錄寫入 `storage/logs/webhook-*.log`。登入後台後可由「Webhook 紀錄」或 `/log-viewer` 查看；Log Viewer 與其 API 都受後台管理員登入保護。

# 程式架構說明

本專案為 Laravel + Vue.js 部落格系統，分為**前台**（文章閱讀）與**後台**（管理員 CMS）兩大區塊。

---

## 目錄結構概覽

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── IndexController.php       # 前台頁面控制器
│   │   ├── Admin/                    # 後台頁面控制器
│   │   └── Api/                      # API 控制器（回傳 JSON）
│   └── Middleware/
│       └── AdminMiddleware.php        # 後台登入驗證中介層
├── Models/                            # Eloquent 模型
│   ├── Article.php                    # 文章（含 tags 多對多）
│   ├── ArticleTag.php                 # 文章標籤 pivot 模型
│   ├── Tag.php                        # 標籤（樹狀結構）
│   ├── About.php                      # 關於我資訊
│   ├── Social.php                     # 社群連結
│   ├── Visit.php                      # 訪客瀏覽紀錄
│   └── Admin.php                      # 管理員帳號
resources/
└── views/
    ├── index.blade.php                # 前台首頁
    ├── article.blade.php              # 前台文章閱讀頁
    ├── layouts/                       # 前台版型元件
    └── admin/                         # 後台所有頁面
routes/
├── web.php                            # 頁面路由
└── api.php                            # API 路由
```
