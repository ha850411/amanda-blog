#!/bin/bash
# 用途: 部署 chaohan 專案到新的端口
set -euo pipefail # 啟用更嚴格的錯誤檢查

# workspace
WORKSPACE="/workspace"
ENV_PATH="$WORKSPACE/chaohan-env"
CONFIG_FILE="$WORKSPACE/chaohan-nginx/nginx/conf.d/default.conf"

# 移動到工作目錄
cd $WORKSPACE

# 使用兼容 macOS 的 grep 方式提取端口
PORT=$(grep -o "proxy_pass http://[^:]*:[0-9]*" "$CONFIG_FILE" | grep -o "[0-9]*$" | head -1)

echo "當前配置的 port 為: $PORT"

# 若 port 為 8080, 則將其改為 8081, 反之
if [ "$PORT" -eq 8000 ]; then
    NEW_PORT=8001
else
    NEW_PORT=8000
fi

echo "NEW_PORT: $NEW_PORT"

OLD_FLODER="$WORKSPACE/chaohan-$PORT"
NEW_FLODER="$WORKSPACE/chaohan-$NEW_PORT"
ENTRYPOINT_FILE="$NEW_FLODER/docker/entrypoint.sh"
DOCKER_COMPOSE_FILE="$NEW_FLODER/docker/docker-compose.yml"

# 若存在 chaohan-temp 目錄，則刪除它
[ -d $NEW_FLODER ] && sudo rm -rf $NEW_FLODER

# 下載專案
git clone https://${GITHUB_TOKEN}@github.com/ha850411/eason-project-chaohan.git -b master $NEW_FLODER

# 替換 .env.example 為 .env
cp $NEW_FLODER/.env.example $NEW_FLODER/.env

# 從 laravel.env 檔案讀取每一行並直接替換到 .env 中
while IFS= read -r line; do
    # 跳過空行和註解行
    if [[ ! -z "$line" && ! "$line" =~ ^# ]]; then
        # 提取 key (= 之前的部分)
        key=$(echo "$line" | cut -d'=' -f1)
        
        # 轉義特殊字符用於 sed (特別處理 / 和 &)
        escaped_line=$(printf '%s\n' "$line" | sed 's/[\/&]/\\&/g')
        
        # 如果 .env 中存在這個 key，就替換整行 (包括空值的情況)
        if grep -q "^$key=" $NEW_FLODER/.env; then
            # 使用不同的分隔符來避免 URL 中的斜線問題
            sed -i "s|^$key=.*|$escaped_line|" $NEW_FLODER/.env
        else
            # 如果不存在，就在檔案末尾新增
            echo "$line" >> $NEW_FLODER/.env
        fi
    fi
done < $ENV_PATH/.env

echo ".env 檔案已更新完成"

# 複製 .env 檔案
cp $ENV_PATH/docker.env $NEW_FLODER/docker/.env

# 更改容器名稱避免重啟容器而不是新建
echo "修改容器名稱: 'sed -i \"s/chaohan-web/chaohan-web-$NEW_PORT/g\" $NEW_FLODER/docker/.env'"
sed -i "s/chaohan-web/chaohan-web-$NEW_PORT/g" $NEW_FLODER/docker/.env

# 修改 /chaohan-temp/docker/entrypoint.sh 中的 port 改為 $NEW_PORT
sed -i "s/$PORT/$NEW_PORT/g" $ENTRYPOINT_FILE
sed -i "s/$PORT:$PORT/$NEW_PORT:$NEW_PORT/g" $DOCKER_COMPOSE_FILE

# 啟動容器
echo "build docker image: 'cd $NEW_FLODER/docker && sudo docker compose build'"
cd $NEW_FLODER/docker && sudo docker compose build

# 啟動新容器
echo "啟動新容器: 'cd $NEW_FLODER/docker && sudo docker compose up -d'"
cd $NEW_FLODER/docker && sudo docker compose up -d

echo "進入 chaohan-nginx 檢查是否啟動"
HEALTH_STATUS=""
RETRY_COUNT=1

# 若回應不是 "ok" 則3秒後重試，最多重試10次
while [ "$HEALTH_STATUS" != "ok" ] && [ $RETRY_COUNT -lt 11 ]; do
    echo "嘗試第 $RETRY_COUNT 次檢測 $NEW_PORT port 的健康狀態..."
    
    # 呼叫健康檢查 API 並取得回應內容
    HEALTH_STATUS=$(cd $WORKSPACE/chaohan-nginx && sudo docker compose exec nginx curl -s app:$NEW_PORT/api/health 2>/dev/null || echo "error")
    
    # 移除可能的空白字符
    HEALTH_STATUS=$(echo "$HEALTH_STATUS" | tr -d '[:space:]')
    
    RETRY_COUNT=$((RETRY_COUNT + 1))
    echo "健康檢查回應: '$HEALTH_STATUS', 重試次數: $RETRY_COUNT"
    
    # 若回應不是 "ok", 等待3秒後重試
    if [ "$HEALTH_STATUS" != "ok" ]; then
        echo "等待3秒後重試..."
        sleep 3
    fi
done

# 如果希望在健康檢查失敗時立即退出
if [ "$HEALTH_STATUS" != "ok" ]; then
    echo "錯誤: 健康檢查失敗，API 回應: '$HEALTH_STATUS'，無法繼續部署"
    exit 1
fi

echo "健康檢查通過，API 回應: '$HEALTH_STATUS'"

# 切換 nginx 配置
echo "切換 nginx port: 'sed -i \"s/$PORT/$NEW_PORT/g\" $CONFIG_FILE'"
sed -i "s/$PORT/$NEW_PORT/g" $CONFIG_FILE

# 進入 chaohan-nginx 容器內 reload
echo "重啟 nginx 配置: 'cd $WORKSPACE/chaohan-nginx && sudo docker compose exec nginx nginx -s reload'"
cd $WORKSPACE/chaohan-nginx && sudo docker compose exec nginx nginx -s reload

# 停止舊容器
echo "停止舊容器: 'cd $OLD_FLODER/docker && sudo docker compose down'"
cd $OLD_FLODER/docker && sudo docker compose down

# 刪除舊 image
echo "刪除舊 image: 'sudo docker rmi chaohan-web-$PORT-app'"
sudo docker rmi chaohan-web-$PORT-app || true

cd $WORKSPACE

# 刪除舊版本備份
[ -d $OLD_FLODER ] && sudo rm -rf $OLD_FLODER

echo "部署完成，新的 chaohan 專案已經啟動在 port: $NEW_PORT"

# 將當前 active 的端口建立軟連結
ln -sfn $NEW_FLODER /workspace/chaohan-active
