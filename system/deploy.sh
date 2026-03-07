#!/bin/bash
# 用途: 部署 chaohan 專案到新的端口
set -euo pipefail # 啟用更嚴格的錯誤檢查

# workspace
WORKSPACE="/workspace/"
SYSTEM_FOLDER="$WORKSPACE/amanda-blog-system"
ENV_PATH="$SYSTEM_FOLDER/app-env"
CONFIG_FILE="$SYSTEM_FOLDER/system/nginx/upstream/active-upstream.conf"
GITHUB_REPO="https://github.com/ha850411/amanda-blog.git"

# 移動到工作目錄
cd $WORKSPACE

# 使用兼容 macOS 的 grep 方式提取端口
CURRENT_SERVICE=$(awk '/server / {print $2}' "$CONFIG_FILE" | tr -d ';' | head -1)

echo "當前配置的 service 為: $CURRENT_SERVICE"

NEW_APP_ENV="green"
NEW_PORT=8801
# 若 CURRENT_SERVICE 中找不到 blue 的字串，本次就以 blue 為 nginx 名稱部署應用程式
if [[ $CURRENT_SERVICE != *"blue"* ]]; then
    NEW_APP_ENV="blue"
    NEW_PORT=8802
fi

echo "NEW_APP_ENV: $NEW_APP_ENV"
NEW_FLODER="amanda-blog-$NEW_APP_ENV"
NEW_IMAGE_NAME="amanda-blog-$NEW_APP_ENV"
NEW_NGINX_NAME="amanda-blog-nginx-$NEW_APP_ENV"
DEPLOY_DIR="$WORKSPACE/$NEW_FLODER"

# Jenkins 可能未提供 USER/GROUP，避免 set -u 造成 unbound variable。
DEPLOY_USER="${SUDO_USER:-${USER:-$(id -un)}}"
DEPLOY_GROUP="${SUDO_GROUP:-${GROUP:-$(id -gn "$DEPLOY_USER")}}"

# 若存在 chaohan-temp 目錄，則刪除它
[ -d $NEW_FLODER ] && sudo rm -rf $NEW_FLODER

# 下載專案
git clone $GITHUB_REPO -b master $NEW_FLODER

# copy env
cp $ENV_PATH/.env $NEW_FLODER/.env 
cp $ENV_PATH/.env.docker $NEW_FLODER/.docker/compose/.env

# 替換並修改 docker env 的 nginx name
sed -i.bak "s/^COMPOSE_PROJECT_NAME=.*/COMPOSE_PROJECT_NAME=$NEW_IMAGE_NAME/" $NEW_FLODER/.docker/compose/.env
sed -i.bak "s/^APP_IMAGE=.*/APP_IMAGE=$NEW_IMAGE_NAME/" $NEW_FLODER/.docker/compose/.env
sed -i.bak "s/^NGINX_NAME=.*/NGINX_NAME=$NEW_NGINX_NAME/" $NEW_FLODER/.docker/compose/.env
# 替換並修改 docker env 的 port
sed -i.bak "s/^APP_PORT=.*/APP_PORT=$NEW_PORT/" $NEW_FLODER/.docker/compose/.env
# 刪除 sed 產生的備份檔
rm -f $NEW_FLODER/.docker/compose/.env.bak

# 啟動容器
echo "啟動新容器: 'cd $NEW_FLODER && make up'"
cd "$DEPLOY_DIR" && make build && make up && make composer-install

# 調整整個新部署目錄權限
echo "調整新部署目錄整體權限..."
sudo chown -R "$DEPLOY_USER":"$DEPLOY_GROUP" "$DEPLOY_DIR"
sudo chmod -R a+rwX "$DEPLOY_DIR"

# 檢查容器是否啟動成功(回應200)
HEALTH_STATUS=""
RETRY_COUNT=1

# 若回應不是 "ok" 則3秒後重試，最多重試10次
while [ "$HEALTH_STATUS" != "ok" ] && [ $RETRY_COUNT -lt 11 ]; do
    echo "嘗試第 $RETRY_COUNT 次檢測 $NEW_PORT port 的健康狀態..."
    
    # 呼叫健康檢查 API 並取得回應內容
    HEALTH_STATUS=$(cd $SYSTEM_FOLDER/system && sudo docker compose exec nginx curl -s http://$NEW_NGINX_NAME/api/health 2>/dev/null || echo "error")
    
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
sed -i.bak "s/server .*/server $NEW_NGINX_NAME;/" $CONFIG_FILE
rm -f "$CONFIG_FILE.bak"

# 重啟 nginx
cd $SYSTEM_FOLDER/system && sudo docker compose exec nginx nginx -s reload

# 停止舊容器
if [ "$NEW_APP_ENV" == "green" ]; then
    OLD_FOLDER="$WORKSPACE/amanda-blog-blue"
else
    OLD_FOLDER="$WORKSPACE/amanda-blog-green"
fi

if [ -d "$OLD_FOLDER" ]; then
    echo "停止舊容器: cd $OLD_FOLDER && make down"
    cd $OLD_FOLDER && make down
    # 刪除舊目錄
    sudo rm -rf $OLD_FOLDER
else
    echo "舊目錄 $OLD_FOLDER 不存在，跳過停止舊容器"
fi

# 若 $WORKSPACE/amanda-blog 存在（首次部署前的原始目錄），則停止並刪除
if [ -d "$WORKSPACE/amanda-blog" ]; then
    echo "偵測到原始 amanda-blog 目錄，停止容器並刪除..."
    cd $WORKSPACE/amanda-blog && make down || true
    sudo rm -rf $WORKSPACE/amanda-blog
    echo "原始 amanda-blog 目錄已刪除"
fi

echo "部署完成，新的專案已經啟動"