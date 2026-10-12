# 體育賽程與轉播來源

## 入口

- `/sports`：公開獨立頁面，只保留 NBA／MLB，不需登入後台；不變更前台選單。
- `/sports?league=MLB`、`/sports?league=NBA`：聯盟捷徑。
- `/sports/events/{id}`：查詢該場的 Sportsurge 與 Streameast 來源；每個來源提供「原連結」，僅已確認支援站內播放的來源額外提供「站內播放器」，都以新分頁開啟。
- 品牌為 **Easonn SPORTS**，不提供返回部落格連結。
- `/sports/player/{token}`：獨立站內播放器，支援網頁全螢幕、Esc 還原、原生螢幕全螢幕、重新載入及停止播放；HLS 來源的播放／暫停／音量使用 video 控制列，iframe 來源由原站控制。
- 支援運動分類、台灣日期、開賽時間篩選及中英文隊名搜尋；不需資料表、migration、API 金鑰或前端編譯。
- 預設 `/sports`（或 `?range=current`）僅顯示台灣時間今天起的賽程，保留今天已開賽的比賽；日期及同日時間由早到晚排列。
- `?range=history` 顯示今天以前、上游仍提供的歷史賽事，日期由近到遠，同日時間由早到晚；並非完整歷史資料庫。
- 指定 `date=YYYY-MM-DD` 時優先查該台灣日期，可直接選擇過去日期；其他分類、聯盟、搜尋及開賽時間條件仍生效。切換賽程範圍會清除指定日期，保留其他條件；「清除」回到預設今天起。
- 頂部總數、聯盟及運動分類場數隨日期範圍／指定日期計算；範圍切換按鈕的場數表示來源中各範圍的總數。非今年的日期標題包含年份，當天標示「今天」。台灣午夜邊界每次請求重新計算，不受上游快取影響。

## 資料與分類

2026-10-04 實測 Sportsurge 的 `/Fullschedule/script.js` 與
`https://raaj648.github.io/streampage2sportsurge/stream.js` 使用：

- `https://streamed.pk/api/matches/all`
- `https://streamed.pk/api/stream/{source}/{id}`
- 隊徽：`https://streamed.pk/api/images/badge/{badge}.webp`

官方格式參考：<https://streamed.pk/docs/images>、<https://streamed.st/docs/matches>。
上游回傳的是運動種類，沒有可靠的聯盟欄位。只有雙方均符合設定中的 MLB／NBA 球隊才保留；其餘聯盟、其他運動及未知球隊不列入 `/sports`。此限制在每次讀取快取後套用，包含舊快取及故障時的最近成功資料；列表、場數、歷史／指定日期與詳情頁皆使用相同範圍。被排除的賽事詳情回傳 404，不查詢串流。
不依字串「basketball」就全部當成 NBA，也不依固定 150 分鐘推定賽事結束。
狀態只表示是否已到排定開賽時間，不代表比分、直播或終場狀態。
日期為 0 的常駐頻道不當作賽程，依賽事 ID 去重，保留不同 ID 的雙重賽。
上游的舊日期不改寫年份；歷史範圍／指定日期同樣僅保留 NBA／MLB。

`config/sports_labels.php` 收錄 MLB、NBA 全部球隊及常見 NFL、NHL、足球國家隊／球會等繁體中文對照。原始英文同時保留，沒有對照的名稱不臆測翻譯。隊徽由上游提供並延遲載入；MLB／NBA 缺圖時使用 `config/sports_badges.php` 中經 ESPN 公開球隊資料核對的 CDN 隊徽，皆失敗時才顯示隊名縮寫。

## Streameast

使用 `config/streameast.php` 明列的 MLB、NBA 分類頁尋找候選賽事。來源按需查詢，首頁不爬取每場播放器；聯盟不屬於 MLB／NBA 時不查詢任何分類頁。

1. 解析賽程清單 `#GelecekMaclar`，以完整雙方隊名比對，容許主客次序相反及少數別名。
2. 再讀取賽事頁的 `SportsEvent` JSON-LD，核對名稱及帶時區的 `startDate`。
3. 開賽時間差不得超過 15 分鐘；同隊隔日或同日雙重賽不混用。
4. 解析 `#wp_player` 及 `#Alternatifler` 的 server 切換 URL 字串，不執行對方 JavaScript。
5. 保留經配對的原站連結；若播放器格式不支援，顯示明確提示。

使用者提供的案例：
<https://streameast.cool/mlb/san-diego-padres-milwaukee-brewers/49170464>
在 2026-10-04 驗證可對應 Streamed 的
`milwaukee-brewers-vs-san-diego-padres-2611749`，取得 2 個 Streameast server，加上當時 8 個 Sportsurge 播放器，共 10 個連結。來源數量會隨上游異動，並不保證每個來源持續可播放。

## 站內播放器：清單經後端、影音由 CDN 直送

2026-10-13 已接通釀酒人對道奇的 StreamEast 頻道 57972。這是單一已驗證賽事，不表示所有 StreamEast 或 GKStreams 來源都已支援。完整來源 URL、賽事 ID、媒體清單、允许的 CDN 路徑與到期時間記錄於 `config/sports_player.php`；不猜測其他頻道 URL。映射到期後停止提供站內播放器，以免常駐頻道播放下一場卻仍掛著舊賽事標題。

- `SportsPlayerService` 登記／顯示頁面不連線上游。播放連結有效 6 小時，每次請求重新確認來源映射、期限及主機。
- 進入播放器頁面後自動載入本地固定版本 `public/vendor/hls/hls-1.7.3.min.js`（官方 hls.js 1.7.3，Apache-2.0 授權檔附於相同目錄），由 ArtPlayer 5.4.0 提供控制介面、HTML video 解碼播放。ArtPlayer 與繁體中文語系同樣由本站提供、僅播放器頁面載入，來源 npm tarball 已驗證 SHA-512，附 MIT 授權。具備 MSE 的瀏覽器使用 HLS.js；沒有 MSE 而支援原生 HLS 的瀏覽器由系統播放，畫質改為系統自動，移除無法控制的播放模式選單。
- Laravel 只代理 `/sports/player/{token}/hls/playlist` 與持有加密票證的子播放清單。**影片片段、初始化片段、金鑰均保留來源 CDN 絕對 URL，不透過本站轉送；無影音代理 fallback。**來源 CDN 必須允許瀏覽器讀取。
- 清單 fetch 使用普通 PHP cURL，不附原站 Origin／Referer、使用者 Cookie 或認證資訊，不跟隨重新導向；只允许設定中的 HTTPS 主機／路徑，限制 256 KiB、連線 3 秒、總共 10 秒。非 HLS 回應、非白名單 URI 及上游失敗均關閉該次請求，以 503 和 Retry-After 回應。
- 子清單票證綁定播放器、URL、類型及期限。請求不得指定任意來源，也不能將票證用來轉送片段／金鑰。舊 `/sports/media` 和 `/sports/player/{token}/source` 均保持 404。
- 清單使用獨立 file cache，每來源固定 8 個槽，成功快取 1 秒、失敗退避 2 秒，加鎖避免同時重複取得。輪詢路由不啟動 session 或寫入 Cookie；保留頻率限制。
- 自動畫質依播放器大小／網路狀況選取，通常上限 1080p；多畫質時可手選。省流量模式自動限制至可用的 720p 以下。這次來源只有 720p，頁面明示省流量模式不會降低其流量，亦不假稱能升為 1080p。
- 均衡／低延遲模式分別以約 3／2 個片段距離作為同步目標。這個來源的片段為 6 秒；顯示的秒數是距 HLS 直播端點，**不是攝影現場到螢幕的延遲**。實際距離會受網路與上游影響，不承諾秒級或次秒播放。
- Worker 解封裝，目標前向緩衝 12 秒、最大緩衝設定 24 秒、保留已看 6 秒、容量目標 24 MiB；這些是 HLS 緩衝設定，不是整個分頁 RAM 上限。畫面資訊最多每秒更新一次。
- ArtPlayer 的暫停會停止 HLS 下載並保留有限緩衝，恢復播放重新取得直播；切到背景且未開 PiP 時自動暫停。停止／離開頁面銷毀播放器、取消計時器並清除影片來源。致命錯誤僅作有限次恢復，失敗後顯示重試／切換來源。
- 保留 sandbox iframe 的相容路徑，但只有 `verified_embed_urls` 中實際驗證過的完整 URL 才可使用。目前 GKStreams 與 StreamEast embed 均未通過現有 sandbox，名單為空；不解除 sandbox 來假報成功。
- 自製 HLS 頁面不載入原站廣告腳本／廣告 iframe，能避開原頁彈窗和頁面廣告；**無法去除已混在影音裡的轉播廣告**。

### 驗證與已知限制

- 初期全後端轉送的臨時原型曾成功播放 177.97 秒、720p，但會產生大量伺服器出站流量；已捨棄該架構並移除 public 臨時端點。
- 隨後清單代理、片段 CDN 直送的原型通過播放驗證。正式頁面於本次修復後亦解碼 1280×720、播放時間持續前進超過 70 秒；Chrome 網路紀錄確認 CDN 片段 200，清單由本站提供。短暫上游失敗有恢復，但這不是整場穩定性保證。
- 修復前頁面已切換至 video，但 JavaScript 尚操作不存在的 iframe，造成 `Cannot read properties of null (reading 'addEventListener')`。現已依 video／iframe 分支初始化，並更新靜態資產版本。
- 以抽查每 6 秒約 3.9 MB 的片段推算，每人每小時約 2.34 GB 觀看流量；只有清單經本站時，本站每人每小時估約 1–5 MB，另加首次載入資產，非 AWS 帳單實測。

### ArtPlayer 介面（2026-10-13）

- HLS 頁面進入後直接播放，不顯示起始確認畫面；瀏覽器拒絕有聲自動播放時改為靜音播放，提供「開啟聲音」按鈕。HLS 頁面使用 `sports-live-player.js`，iframe 頁面保留 `sports-player.js`，不混用兩種初始化流程。
- 播放器內整合播放／暫停、音量、畫質、均衡／低延遲／省流量、畫面比例、回到直播、子母畫面、劇院模式與全螢幕。只顯示來源實際提供的畫質；有多音軌時才增加音軌選單。
- 使用直播模式，隱藏不適合直播的總長度與一般影片進度列；不提供沒有來源支援的字幕、倍速或下載按鈕。
- 控制列自動收合；支援鍵盤操作、手機鎖定控制列及触控。子母畫面與原生全螢幕依裝置瀏覽器支援，需手機實機另驗證。
- 不啟用廣告／分析插件、截圖、背景迷你播放器、逐幀 UI 輪詢；沿用原有 HLS 小緩衝、Worker、CDN 片段直送與清單快取。
- 升級不改變 GKStreams 的存取限制，也不會增加新的影音代理或 AWS 片段流量。

### GKStreams／Sportsurge 調查

GKStreams 與 Sportsurge 在此賽事使用相同 Streamed 來源。公開 [Streams API](https://streamed.pk/docs/streams) 回傳 `embedUrl`，不是固定可轉用的 HLS URL。

- 原播放頁可正常取得動態 `/secure/…/playlist.m3u8`，主清單有 1080p／540p；初始化包含 `lock.js`、`lock.wasm` 及二進位 POST `/fetch`。沒有逆向簽章、偽造請求來源或重播認證。
- 已測 Fox HD、Fox SD、Main HD 等來源：原頁能解碼；相同網址交由獨立 HLS.js demo 或普通後端請求，得到 403。拒絕回應未附跨來源標頭，導致播放器另報 CORS，但根本觀察是上游拒絕。不能僅更換播放器或複製 m3u8 就認定可接入。
- 原頁請求分開載入影音與廣告脚本／ad.html。直接開 embedUrl 可以省去賽程網站外框，但播放器自身仍可能有廣告。使用者瀏覽器的廣告阻擋是自用觀看的另一個方向，未在該來源驗證全面有效，也不能讓本站替所有訪客跨網域刪除 iframe 內容。
- 若要穩定提供本站所有訪客無頁面廣告的播放，需要支援本站播放的來源介面。現階段可用的是上述單場 StreamEast HLS 路徑，不將它泛化為 GKStreams 已成功。

## 快取、故障與維護

- Streamed 快取 60 秒；Streameast 快取 120 秒；來源失敗時最多使用 15 分鐘內的最近成功結果並標示過期。
- 失敗後退避 15 秒；單一 HTTP 請求最多 8 秒、連線最多 3 秒。Streamed provider 最多同時 5 個請求。
- 個別來源故障不影響其他来源；首次取得賽程失敗則回傳 503 並顯示重試提示。
- 所有資料使用 Blade 跳脫。播放器起始 URL 只接受設定允許的 HTTPS 主機；不接受任意使用者 URL。賽程／來源資料的後端擷取不跟隨上游重新導向；iframe 內的請求由使用者瀏覽器與原站處理。
- Streameast 仰賴 HTML 結構，鏡像、路徑、播放器主機變更時需重新確認並調整設定／解析器。遇到挑戰頁以故障處理。
- 保持 Laravel cache store 可寫。沿用現有部署流程；若正式環境有 config／route／view cache，部署時正常更新這些快取即可。

測試：

```sh
php artisan test --filter='SportsTest|StreameastTest|SportsPlayerTest|SportsHlsTest' --do-not-cache-result
```

涵蓋台灣日期、分類、翻譯、隊徽 fallback、來源去重、部分故障、快取過期、來源網址驗證、HTML 跳脫、不同日期對戰誤配防護、client iframe 來源驗證、播放器連結過期、移除主機後重新驗證、HTML 跳脫，以及舊解析／影音端點（含有效舊票證）無法再發出網路請求。
