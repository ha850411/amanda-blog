# Amanda Blog

## LINE 賽程 Bot

LINE webhook 收到合法賽程指令後會先立即回覆「賽程查詢中，請稍候…」，再把查詢工作丟進 queue。Worker 完成 bo3.gg 賽程、賠率與圖片處理後，會使用 LINE Push Message API 將完整結果送回原本的個人、群組或多人聊天室。

由於 LINE reply token 只能使用一次，第一段「查詢中」使用 Reply Message；完整結果則使用 Push Message。

正式環境使用 `QUEUE_CONNECTION=database`，部署時需要先執行 migration，並確保 compose 中的 queue worker 正常運作。

---

此專案其餘說明請參考既有程式與環境設定。
