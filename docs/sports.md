# 體育賽程與轉播來源

## 入口

- `/sports`：公開獨立頁面，不需登入後台；不變更前台選單。
- `/sports?league=MLB`、`/sports?league=NBA`：聯盟捷徑。
- `/sports/events/{id}`：查詢該場的 Sportsurge 與 Streameast 來源；每個來源提供「原連結」，僅已確認支援站內播放的來源額外提供「站內播放器」，都以新分頁開啟。
- 品牌為 **Easonn SPORTS**，不提供返回部落格連結。
- `/sports/player/{token}`：獨立站內播放器，支援網頁全螢幕、Esc 還原、原生螢幕全螢幕、重新載入及停止播放；播放／暫停／音量由原站播放列控制。
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
上游回傳的是運動種類，沒有可靠的聯盟欄位。只有雙方均符合設定中的 MLB／NBA 球隊才標示該聯盟；其他比賽維持原運動分類，未知分類亦保留。
不依字串「basketball」就全部當成 NBA，也不依固定 150 分鐘推定賽事結束。
狀態只表示是否已到排定開賽時間，不代表比分、直播或終場狀態。
日期為 0 的常駐頻道不當作賽程，依賽事 ID 去重，保留不同 ID 的雙重賽。
2026-10-04 追查 `LFC 63: Legacy Fighting Championship 63`，上游 `/matches/all` 的 `date` 原值為 `1480651200000`，正確換算為台灣時間 `2016-12-02 12:00`。此筆為來源提供的舊日期，並非本站解析錯誤；保留原日期，僅在歷史範圍／指定日期中顯示，不改寫年份。

`config/sports_labels.php` 收錄 MLB、NBA 全部球隊及常見 NFL、NHL、足球國家隊／球會等繁體中文對照。原始英文同時保留，沒有對照的名稱不臆測翻譯。隊徽由上游提供並延遲載入；MLB／NBA 缺圖時使用 `config/sports_badges.php` 中經 ESPN 公開球隊資料核對的 CDN 隊徽，皆失敗時才顯示隊名縮寫。

## Streameast

使用 `config/streameast.php` 明列的 MLB、NBA、WNBA、NCAAB、NFL、CFB、NHL、格鬥、WWE、F1 分類頁尋找候選賽事。來源按需查詢，首頁不爬取每場播放器。

1. 解析賽程清單 `#GelecekMaclar`，以完整雙方隊名比對，容許主客次序相反及少數別名。
2. 再讀取賽事頁的 `SportsEvent` JSON-LD，核對名稱及帶時區的 `startDate`。
3. 開賽時間差不得超過 15 分鐘；同隊隔日或同日雙重賽不混用。
4. 解析 `#wp_player` 及 `#Alternatifler` 的 server 切換 URL 字串，不執行對方 JavaScript。
5. 保留經配對的原站連結；若播放器格式不支援，顯示明確提示。

使用者提供的案例：
<https://streameast.cool/mlb/san-diego-padres-milwaukee-brewers/49170464>
在 2026-10-04 驗證可對應 Streamed 的
`milwaukee-brewers-vs-san-diego-padres-2611749`，取得 2 個 Streameast server，加上當時 8 個 Sportsurge 播放器，共 10 個連結。來源數量會隨上游異動，並不保證每個來源持續可播放。

## 站內播放器：僅由 client 載入影音

- 站內頁面提供原站 iframe 播放器。點選「播放直播」後，使用者瀏覽器直接載入經允許的第三方 HTTPS 播放頁，再由原站播放器向 CDN 取得清單、片段與金鑰。**直播影音不經過本站伺服器，也不由本站下載、轉送、快取或儲存。**
- Laravel 僅提供網站頁面、賽程及來源連結資料；`SportsPlayerService` 只登記與驗證來源連結，不發出任何對外 HTTP 請求。播放器連結有效 6 小時；顯示前再次驗證 HTTPS、主機白名單、埠號、URL 格式及站內播放支援狀態。不支援或未確認的來源不登記播放器連結，已發出的舊連結也會回傳 410。
- `config/sports_player.php` 的 `verified_embed_urls` 只接受在現有 sandbox 下實際確認影片能播放的完整 URL，不因來源屬於允許的網域或 iframe 觸發 `load` 就當作可播放。這是明確的支援名單，不是即時播放健康檢查；目前無已通過驗證的來源，名單為空，來源列表只顯示原連結。後續確認支援後才能逐條加入，來源失效時移除；不會自動放行同主機的其他頻道。
- 已移除 `/sports/player/{token}/source`、`/sports/media` 路由及 HLS 解析／影音轉送實作；包含尚未過期的舊影音票證，請求也回傳 404，不會抓取任何影音。站內頁面不再載入 HLS.js；來源不支援 iframe 時，使用者須切換來源或開啟原連結，絕不退回後端轉送。
- iframe 起初沒有 `src`；重新載入會重新開啟來源，停止播放會移除 `src`，終止該播放器。原站播放／暫停／音量由原站控制列處理。跨來源 iframe 的 `load` 事件不代表影片已播放，因此本站僅標示「原站播放器」，不假報「播放中」。
- iframe sandbox 只允許 `allow-scripts allow-same-origin allow-presentation`，不允許彈出新視窗或導向頂層頁面。原站內嵌廣告與相關腳本仍可能載入；不承諾無廣告。若來源拒絕 sandbox 或嵌入，保留原連結供使用者開啟，不解除限制來強行播放。
- 2026-10-04 瀏覽器實測：`embed.st/embed/admin/ppv-ufc-332-silva-vs-wang/1` 在此 sandbox 設定下明確顯示「Remove sandbox attributes on the iframe tag」，因此未通過站內播放驗證。停止播放、網頁全螢幕及螢幕全螢幕切換可操作；瀏覽器未呼叫本站來源解析或影音端點。此來源只提供原連結，不顯示站內播放器入口。
- 網頁全螢幕使用 CSS 填滿分頁；「離開網頁全螢幕」還原並保留播放器，焦點在本站頁面時也可按 Esc。焦點在跨來源 iframe 內時，按鍵由原站接收。原生全螢幕採 Fullscreen API，瀏覽器拒絕時提示改用網頁全螢幕。
- 後端仍會取得賽程與來源連結的少量資料；「不經過本站」專指直播影音流量，並非網站本身完全沒有網路請求。

## 快取、故障與維護

- Streamed 快取 60 秒；Streameast 快取 120 秒；來源失敗時最多使用 15 分鐘內的最近成功結果並標示過期。
- 失敗後退避 15 秒；單一 HTTP 請求最多 8 秒、連線最多 3 秒。Streamed provider 最多同時 5 個請求。
- 個別來源故障不影響其他来源；首次取得賽程失敗則回傳 503 並顯示重試提示。
- 所有資料使用 Blade 跳脫。播放器起始 URL 只接受設定允許的 HTTPS 主機；不接受任意使用者 URL。賽程／來源資料的後端擷取不跟隨上游重新導向；iframe 內的請求由使用者瀏覽器與原站處理。
- Streameast 仰賴 HTML 結構，鏡像、路徑、播放器主機變更時需重新確認並調整設定／解析器。遇到挑戰頁以故障處理。
- 保持 Laravel cache store 可寫。沿用現有部署流程；若正式環境有 config／route／view cache，部署時正常更新這些快取即可。

測試：

```sh
php artisan test --filter='SportsTest|StreameastTest|SportsPlayerTest' --do-not-cache-result
```

涵蓋台灣日期、分類、翻譯、隊徽 fallback、來源去重、部分故障、快取過期、來源網址驗證、HTML 跳脫、不同日期對戰誤配防護、client iframe 來源驗證、播放器連結過期、移除主機後重新驗證、HTML 跳脫，以及舊解析／影音端點（含有效舊票證）無法再發出網路請求。
