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
