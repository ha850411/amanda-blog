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

# Google AdSense 廣告收益

網站透過 Google AdSense 放送廣告。網站端已提供帳戶驗證 meta tag、`/ads.txt` 與自動廣告程式碼；實際帳戶啟用、網站審查、自動廣告及收款資料仍需在 AdSense 後台完成。

## 1. 設定發布商 ID 並部署

`config/adsense.php` 已設定從本站 AdSense 後台確認的公開發布商 ID `pub-8869697978199559`，並預設載入廣告程式碼。既有正式環境未設定下列變數時，push 後可直接透過 CI/CD 部署；此 ID 是公開驗證資訊，不是金鑰。若正式環境已設定空白 ID 或關閉廣告，請移除覆寫或改為：

```env
ADSENSE_PUBLISHER_ID=pub-8869697978199559
ADSENSE_ENABLED=true
```

部署本次程式後，以現有部署流程重新產生 Laravel 設定；若使用本專案的 Docker Compose：

```sh
cd .docker/compose
docker compose exec -T service php artisan config:cache
docker compose exec -T service php artisan view:clear
```

有有效 ID 時，首頁 `<head>` 會包含 `google-adsense-account` meta tag，`https://amanda-blog.com/ads.txt` 會回傳自己的 Google 授權紀錄。`ADSENSE_ENABLED=false` 時仍可驗證；沒有 ID 或格式錯誤時不輸出驗證碼、廣告程式碼，`/ads.txt` 回傳 404。不要另外建立 `public/ads.txt`，以免靜態檔蓋過動態設定。

## 2. 驗證網站與設定隱私訊息

在 AdSense 選擇「meta 標記」或「ads.txt 程式碼片段」驗證方式，確認正式網站已上線後點選「驗證」及「要求審查」。`robots.txt` 沒有封鎖首頁、公開文章或 `/ads.txt`；若 Cloudflare 對 Google 廣告檢索器顯示驗證挑戰，需在 Cloudflare 設定中允許合法檢索。

在「隱私權與訊息」設定及發布適用的訊息。向歐洲經濟區、英國與瑞士使用者提供個人化廣告時，須使用 Google 認證 CMP；可選用 AdSense 內建的 Google CMP。將隱私權政策網址設為 `https://amanda-blog.com/privacy`，並設定同意選项與撤回入口。本站隱私權頁面與 Cookie 說明不會取代 CMP。

隱私權頁面已說明現有 IP／日期統計、工作階段 Cookie、Google Tag Manager 及廣告資料使用，聯絡信箱沿用網站公開的 `summer.hung222@gmail.com`。上線時應確認 AdSense 的廣告技術供應商、GTM 標籤、資料使用方式與政策內容一致。

## 3. 啟用自動廣告與收款

在 AdSense「廣告」為網站啟用自動廣告並套用設定，並完成隱私訊息設定。正式環境預設已啟用程式碼；若 `.env` 有覆寫，請確認 `ADSENSE_ENABLED=true` 並重新執行 `config:cache`。本機開發請保留 `.env.example` 的 `ADSENSE_ENABLED=false`。程式碼會在首頁、分類及公開文章的 `<head>` 各載入一次；密碼保護文章、隱藏文章、後台、錯誤頁及隱私權頁不載入廣告程式碼。

網站通過 Google 審查且帳戶啟用後才會開始放送廣告。付款資料、身分／地址驗證、稅務資訊與付款方式，依 AdSense 後台顯示的要求由帳戶持有人完成。放上程式碼本身不代表審查通過或已可領款。

上線後檢查：

```sh
curl -fsS https://amanda-blog.com/ads.txt
curl -fsS https://amanda-blog.com/ | rg 'google-adsense-account|adsbygoogle'
curl -fsS https://amanda-blog.com/privacy
```

確認兩處 ID 都屬於自己的 AdSense 帳戶。若暫停廣告，將 `ADSENSE_ENABLED=false` 並重新產生設定快取即可，驗證碼與 `ads.txt` 仍會保留。

官方文件：[連結網站](https://support.google.com/adsense/answer/7584263?hl=zh-Hant)、[設定自動廣告](https://support.google.com/adsense/answer/9261307?hl=zh-Hant)、[隱私權政策必要內容](https://support.google.com/adsense/answer/1348695?hl=zh-Hant)、[Google 同意聲明管理規定](https://support.google.com/adsense/answer/13554116?hl=zh-Hant)。

# Runtime image

PHP 8.4 runtime 使用固定 digest 的 Debian Trixie base image，採用 OpenSSL 3.5。Stake API 的實測中，相同代理、Token、HTTP/1.1 與 request 在舊 Bookworm／OpenSSL 3.0 runtime 回傳 403，Trixie runtime 則回傳 HTTP 200 與有效 GraphQL 資料；此結果不代表所有 403 都由 TLS 環境造成。TLS 更新不需要新增 Cookie 或關閉憑證驗證。

此修改必須重建 runtime image，並讓 `service`、`queue`、`scheduler` 重新建立容器後才會生效；只重啟 queue worker 不會更新底層 TLS 函式庫。若環境有覆寫 `PHP_IMAGE`，請同步改用 `.docker/Dockerfile` 內的 Trixie image。

目前正式部署的 EC2 與線上 PHP image 都是 ARM64，因此 `RUNTIME_PLATFORMS` 預設為 `linux/arm64`。建置架構應以最終部署主機為準；若改部署到 x86_64 主機，請在 Jenkins 環境設定 `RUNTIME_PLATFORMS=linux/amd64`，讓 `make runtime-tag` 與後續建置使用相同架構。

透過 `make deploy-up` 部署時，Runtime image tag 會依 `.docker/Dockerfile`、PHP base image、Composer base image 與 `RUNTIME_PLATFORMS` 自動產生；相同建置內容會沿用既有 image，建置內容有變更時則會自動使用新 tag。可執行 `make runtime-tag` 查看目前 tag，不需在 `.env` 手動維護 `RUNTIME_IMAGE_TAG`。

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

輸入 `!help` 才會顯示使用說明；一般訊息與無效指令不會回覆。指令格式為 `!lol 08/11`、`!lol 0912`、`!lol 0912~0913`、`!val 今天`、`!cs 明天`；只輸入 `!match`、`!lol`、`!val`、`!cs` 或 `!mlb` 時，日期預設為台灣時間的今天。日期支援 `MMDD`（如 `0912`）、`MM/DD`、`MMDD~MMDD` 區間（如 `0912~0913`，區間最多 7 天）、今天、明天與後天；查詢今天時顯示滾球中和尚未開打的賽事，滾球賽事會加上標記，並在資料可取得時顯示目前比分；電競預設只查 S Tier，MLB 不套用 Tier 篩選。可加上 `tier=s`、`tier=a,b`、`tier=all`、`limit=5`、`team=G2` 等參數，例如 `!cs 08/11 tier=s limit=5`；未指定 `limit` 時最多顯示 19 場。電競賽程來自 bo3.gg，顯示時間使用 `BO3_TIMEZONE`（預設為 Asia/Taipei）。LoL、VALORANT 與 CS2 賽程會另外透過 `BO3_API_URL` 取得最近五次 Head to Head 的系列賽勝場、小局總比分，以及每次交手的日期、BO 賽制、比分與勝方；逐場資訊會列在賽程圖中各對局的右側。資料暫時無法取得時仍會正常顯示賽程。

MLB 使用官方 Stats API，不需 API 金鑰。`!mlb` 查今天、`!mlb 明天` 查明天，亦支援 `!mlb 0921~0923`；`!match` 預設整合 LoL、VALORANT、CS2 與 MLB。可用 `!match 明天 game=lol/mlb` 指定項目，或 `!mlb 明天 team=道奇` 在追蹤清單內再篩選球隊。日期以台灣時間為準，會補查美國前一天的賽程後依實際開賽時間篩選；兩支追蹤球隊互打只列一次，雙重賽分別列出，客隊在左、主隊在右。MLB 目前提供賽程、比分與各隊近況，獨贏賠率與歷史交手未串接。

追蹤球隊設定在 `config/mlb.php` 的 `team_ids`，預設如下；只要任一隊在清單中即會納入。檔案內亦列有其餘 MLB 球隊 ID，修改清單即可增減，設成 `[]` 則停用 MLB 查詢：

```php
'team_ids' => [
    119, // 洛杉磯道奇
    158, // 密爾瓦基釀酒人
],
```

修改設定後須重啟常駐 queue worker；若部署有使用 `php artisan config:cache`，需同步重新產生 Laravel 設定。這是部署設定載入，賽程及戰績資料不使用快取。

所有項目都會顯示**兩隊各自近 5 場的對手、日期、賽制、勝敗與比分**（由新到舊），電競列系列賽比分，MLB 列終場得分。各隊近況的比分固定為「本隊：對手」，即使歷史資料的左右順序或主客場不同也會轉換。未滿 5 場會標示實際場數，資料失敗顯示「暫無資料」；查過去日期時，以該場開賽前的結果計算，未來賽程則以查詢當下為準。MLB 也會查詢對手的近況，不受追蹤球隊清單限制；先批次查近 30 天，僅在不足 5 場時向前延伸至 400 天。新版賽程圖將兩隊的逐場近況並排呈現，右側雙方交手則逐場列出兩隊名稱與比分：勝方為綠色、敗方為紅色、和局為金色，並搭配勝／敗／和文字。文字降級回覆也包含完整對手與比分；超過單則長度時依行分段，最多 5 則，超過整批上限會明確提示部分內容省略。

賽程圖採「比賽資訊｜第一隊近 5 場｜第二隊近 5 場｜雙方交手」四欄排版，將賽事時間、隊名、即時比分與賠率集中在最左欄，並提供滾球中賽事实時高光紅框與狀態膠囊。近期對手與比分保留原字級，以橫向排列降低每場高度，完整列出五筆紀錄；MLB 即時比分另外標示客隊與主隊，並在無歷史對戰時呈現精緻的置中空狀態視覺。

效能採用**批次請求、最多 5 個並行請求、同次查詢去重及先篩選後補資料**，不讀寫賽程、戰績或盤口快取。bo3.gg 近況與交手共用同一批請求，近況請求以 `with=teams` 一併取得對手名稱，已取得的對局詳情會提供給後續查詢使用；MLB 則合併可見隊伍與對手的查詢，並限制 API 回傳欄位。每次指令仍重新向來源取得資料。單一賽程來源失敗時會保留另一來源的結果並顯示提示。

當天 LoL 與 CS2 滾球比分不使用 Laravel Cache：設定 `ODDS_API_KEY` 後，每次指令會直接呼叫 Odds-API.io `/events/live` 更新系列賽比分。CS2 的 Odds API 資料目前只提供系列與已完成地圖的勝負，不會把 `scores.periods` 的地圖勝負誤當成當前地圖回合比分；成功匹配 Odds API 賽事時，也不再顯示可能已落後的 bo3.gg 當局回合分。LoL 當局擊殺會直接讀取 Riot LoL Esports 官網目前進行中的 game ID，再呼叫 `feed.lolesports.com/livestats/v1/window/{gameId}` 取得最新 frame。即時來源失敗時保留 bo3.gg 比分作為備援。Riot LoL Esports feed 是官網內部且未公開支援的接口，可能在 Riot 改版後需要同步調整。

LINE webhook 會先記錄事件並快速回傳 HTTP 200，再由 queue worker 處理外部 API、圖片與 LINE 回覆；正式環境需執行 `php artisan migrate --force`，並保持 `php artisan queue:work --tries=3 --backoff=2 --timeout=300` 常駐。queue connection 的 `retry_after` 必須大於 300 秒（預設 330 秒），避免同一工作被重複執行。若事件在 queue 等待與背景處理的總時間超過 `LINE_REPLY_TOKEN_SAFE_WINDOW_SECONDS`（預設 45 秒），Bot 會跳過即將失效的 reply token，直接以事件來源 ID 改用 push message；若 Reply API 提早拒絕 token，也會 fallback 成 push。queue dispatch 失敗時 webhook 會回傳 HTTP 500，讓已開啟 webhook redelivery 的 LINE channel 能重新投遞事件。

查到賽程時會回覆一張可點擊放大的賽程圖，並另外用一則文字訊息提供符合遊戲、日期與 Tier 條件的 bo3.gg 總覽連結，不會再為每場賽事附上個別連結。圖片會顯示賽事名稱與 BO 賽制，並以 Odds-API.io 的日期、開賽時間與雙方隊名匹配盤口。設定 `ODDS_API_KEY` 後，會依 `ODDS_API_BOOKMAKER_PRIORITY` 選擇同一家莊家的完整雙邊 ML（預設 Stake 優先、Bet365 備援），不會混搭兩家盤口；`ODDS_API_BOOKMAKERS` 留空時自動使用帳號已選擇的莊家。若所選莊家沒有完整雙邊 ML，會改用 bo3.gg 該場賽事提供的同一家莊家獨贏盤，兩邊都沒有時才顯示「暫無盤口」。圖片會直接寫入 `LINE_SCHEDULE_IMAGE_DISK`（預設固定為 `s3`），不會儲存在主機的 `public/storage`；S3 的 `Storage::url()` 必須產生公開 HTTPS 網址。Scheduler 每天 03:30（台灣時間）只清理 `line-schedules/` 下超過 `LINE_SCHEDULE_IMAGE_RETENTION_DAYS`（預設 7 天）的物件，因此 S3 IAM 除了上傳與讀取外，也需要 `ListBucket` 與 `DeleteObject` 權限。若圖片產生或儲存失敗，Bot 會降級成文字回覆，且只保留一個總覽連結。

Webhook 執行紀錄寫入 `storage/logs/webhook-*.log`。登入後台後可由「Log」或 `/log-viewer` 查看；Log Viewer 與其 API 都受後台管理員登入保護。

日期區間查詢（例如 `!match 0914~0918`）會將各遊戲、各日期的 bo3.gg 網頁與賽程 API 請求合併處理，最多同時執行 5 個請求。查詢會先套用隊伍篩選；未包含今天時，另先排序與套用顯示場數限制，再補抓缺少的賽事詳情；BO 賽制與賽事名稱優先沿用已取得的賽程資料。包含今天的查詢仍會先更新滾球資訊，再排除已結束的場次。

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
