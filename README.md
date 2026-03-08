# Amanda Blog System (系統基礎架構與部署環境)

本專案 (`amanda-blog-system`) 是 [Amanda Blog](https://github.com/ha850411/amanda-blog) 的伺服器基礎架構與自動化部署管理專案。它基於 Docker 與 Docker Compose 構建，提供了完整的 CI/CD 流程、藍綠部署 (Blue-Green Deployment) 機制、資料庫服務以及系統級別的反向代理路由。

---

## 🏗️ 系統架構總覽

整個系統是以微服務架構為核心建立的，主要劃分為三個核心區塊：
1. **System (系統與路由層)**：負責對外接收流量 (Nginx)、並提供 CI/CD 服務 (Jenkins)。
2. **Database (資料層)**：集中管理全域共用的資料庫 (MySQL) 與快取伺服器 (Redis)。
3. **Application (應用層)**：透過部署腳本動態建立的獨立專案環境，利用藍、綠兩套環境交替實現零停機更新。

### 服務連線拓樸
- **使用者 (Client)** -> `System (Nginx)` -> `動態路由 (Blue/Green App Nginx)` -> `App 應用程式` -> `Database (MySQL/Redis)`
- **開發者 (Developer)** -> `GitHub` -> `Webhook` -> `Jenkins (CI/CD)` -> `觸發 deploy.sh` -> 建立新環境並切換 Nginx 配置。

---

## 📂 目錄結構

```text
amanda-blog-system/
├── init.sh                 # 伺服器初始環境建置腳本 (安裝 Docker、設定權限等)
├── system/                 # 系統核心服務
│   ├── docker-compose.yml  # 定義 Nginx 與 Jenkins 服務
│   ├── deploy.sh           # 藍綠部署自動化腳本
│   ├── nginx/              # Nginx 系統配置 (含動態切換後端的設定檔)
│   └── Dockerfile          # Jenkins 客製化 Image 設定
├── database/               # 全域資料庫與快取服務
│   ├── docker-compose.yml  # 定義 MySQL, Redis, phpMyAdmin
│   ├── custom.cnf          # MySQL 自定義設定檔
│   └── Makefile            # 管理資料庫服務的快捷指令
├── app-env/                # 應用程式環境變數檔
│   ├── .env                # 給應用程式主機體的正式環境變數
│   └── .env.docker         # 給 Docker Compose 使用的變數設定
└── crontab/                # 系統例行或排程服務配置
```

---

## ⚙️ 核心組件與運作機制

### 1. Nginx 系統反向代理 (Reverse Proxy)
所有的外部 HTTP/HTTPS 請求皆由 `system/nginx` 容器統一處理。
- 設定檔位置位於 `system/nginx/conf.d/default.conf`。
- 它依賴 `system/nginx/upstream/active-upstream.conf` 中的變數 `$active_backend` 來決定當前流量要導向藍 (Blue) 或綠 (Green) 環境。部署完成後會動態修改此變數，確保服務不中斷。

### 2. CI/CD 與 Jenkins
`system/docker-compose.yml` 運行了一個繼承宿主機 Docker Socket 權限的 Jenkins 容器。
- Jenkins 具備操作 Docker 及建立其他容器的權限 (`DOCKER_GID` 同步機制)。
- 當專案程式碼合併至主分支或觸發建置時，Jenkins 將呼叫 `system/deploy.sh` 啟動自動化藍綠部署。

### 3. 資料庫與快取 (Database & Redis)
在 `database/` 目錄中，定義了全域唯一共用的服務層：
- **MySQL**：應用的主要資料庫，由藍綠雙邊獨立的應用容器共用連接。
- **Redis**：用做 Session 管理與全域快取。
- **phpMyAdmin**：提供視覺化的資料庫管理介面 (選擇性啟動)。

### 4. 藍綠部署機制 (Blue-Green Deployment)
系統核心的 `system/deploy.sh` 腳本定義了嚴謹且自動化的無停機服務上線流程：
1. **偵測目前狀態**：讀取 `active-upstream.conf` 的狀態，假設當前為 Blue，下一次部署便會建立 Green 環境並配置新的 HTTP Port。
2. **準備全新環境**：藉由 `git clone` 從遠端拉取最新的主分支程式碼，將 `app-env` 內的環境變數 `.env` 動態注入到新建立的資料夾內，並更新裡面 `docker-compose.yml` 需要的 Nginx Name、Image 與 Port 等對應變數。
3. **啟動新服務**：透過 `make build && make up` 指令在新資料夾內建立並運行全新的應用層容器群與載入最新的依賴包 (Composer Install)。
4. **系統健康檢查 (Health Check)**：持續嘗試呼叫新服務的 `/api/health` 介面。若未能在指定次數內收到底層返回的 `ok`，即代表啟動失敗，中斷本次部署並保留現有的穩定環境。
5. **執行單元測試**：在服務上線前啟動專屬隔離的測試資料庫容器群，執行資料表遷移與專案的 Unit Tests。若測試失敗便會放棄啟動新節點，確保上線服務皆符合測試要求。
6. **切換流量與資源回收**：所有測試項目都通過後，將 `active-upstream.conf` 檔案重新指向剛建好且測試通過的新服務節點，並命令 Nginx 重啟更新關聯 (`nginx -s reload`)。流量轉移完畢後，安全關停並刪除舊目錄所有佔用的容器與資源。

---

## 🚀 系統初始化流程 (伺服器初次建置)

要在全新的主機 (如 AWS EC2/Linux 或 Ubuntu 環境) 上初始化系統：

1. **基礎設定**：給予腳本執行權限並執行 `./init.sh`，腳本會自動安裝 Git、Docker、Docker Compose 插件、更換時區（Asia/Taipei）並建立 `/workspace` 權限資料夾。
   ```bash
   chmod +x init.sh
   ./init.sh
   ```
2. **套用權限變更**：初次執行完成後，需完整登出再重新登入伺服器，使 `docker` 用戶群組的權限正式生效。
3. **配置正式環境變數**：在 `app-env/` 以及 `database/` 目錄內放入正式站點需要的 `.env` 機密檔案。
4. **啟動全域資料群**：切換至存放資料系統的目錄並背景運行。
   ```bash
   cd database/
   docker compose up -d
   ```
5. **啟動核心系統層**：啟動對外的代理伺服器及 CI/CD。
   ```bash
   cd ../system/
   docker compose up -d
   ```
6. **執行首次部署**：至 Jenkins 設定相關組態，或可手動執行 `/workspace/amanda-blog-system/system/deploy.sh` 來觸發第一次的環境建置。

---

## 📌 注意事項
- 請確保使用者和 Jenkins 容器均有存取 `/var/run/docker.sock` 的權限，這是部署腳本生成 Blue/Green 容器的關鍵。
- 目錄被硬編碼依賴並安裝於 `/workspace` 根目錄之下（由 `init.sh` 自動建立），請確保磁碟容量與權限皆有妥善掛載。
