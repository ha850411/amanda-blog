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

# LINE 賽程 Bot

Webhook URL 設為 `https://你的網域/api/line/webhook`，並在 `.env` 設定：

```env
LINE_CHANNEL_SECRET=你的_Messaging_API_Channel_Secret
LINE_CHANNEL_ACCESS_TOKEN=你的_Channel_Access_Token
```

指令格式為 `!lol 08/11`、`!val 今天`、`!cs 明天`，日期支援 `MM/DD`、今天、明天與後天，預設只查 S/A Tier。可加上 `tier=s`、`tier=a,b`、`tier=all`、`limit=5`、`team=G2` 等參數，例如 `!cs 08/11 tier=s limit=5`。賽程來自 bo3.gg，顯示時間使用 `BO3_TIMEZONE`（預設為 Asia/Taipei）。

每場回覆會顯示 bo3.gg 的賽事名稱與 BO 賽制，並以 Odds-API.io 的日期、開賽時間與雙方隊名匹配盤口。設定 `ODDS_API_KEY` 後，會依 `ODDS_API_BOOKMAKER_PRIORITY` 選擇同一家莊家的完整雙邊 ML（預設 Stake 優先、Bet365 備援），不會混搭兩家盤口；`ODDS_API_BOOKMAKERS` 留空時自動使用帳號已選擇的莊家，沒有匹配盤口時顯示「暫無盤口」。

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
