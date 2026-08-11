#!/bin/bash
# 用途: 部署 Amanda Blog 到新的端口
set -euo pipefail # 啟用更嚴格的錯誤檢查

# Amazon Linux/Jenkins 使用下列預設值；本機整合測試可透過環境變數覆寫，
# 不需要另外維護 macOS 專用腳本。不要使用 WORKSPACE：它是 Jenkins 內建變數，
# 內容會是 /var/jenkins_home/workspace/<job>，不是部署主機的 /workspace。
DEPLOY_ROOT="${AMANDA_DEPLOY_ROOT:-/workspace}"
DEPLOY_ROOT="${DEPLOY_ROOT%/}"
SYSTEM_FOLDER="${SYSTEM_FOLDER:-$DEPLOY_ROOT/amanda-blog-system}"
ENV_PATH="${ENV_PATH:-$SYSTEM_FOLDER/app-env}"
CONFIG_FILE="${CONFIG_FILE:-$SYSTEM_FOLDER/system/nginx/upstream/active-upstream.conf}"
GITHUB_REPO="${GITHUB_REPO:-https://github.com/ha850411/amanda-blog.git}"
APP_BRANCH="${APP_BRANCH:-master}"
APP_NAME_PREFIX="${APP_NAME_PREFIX:-amanda-blog}"
GREEN_PORT="${GREEN_PORT:-8801}"
BLUE_PORT="${BLUE_PORT:-8802}"
APP_ENV_FILE="${APP_ENV_FILE:-$ENV_PATH/.env}"
DOCKER_ENV_FILE="${DOCKER_ENV_FILE:-$ENV_PATH/.env.docker}"
HEALTHCHECK_MODE="${HEALTHCHECK_MODE:-container}"
RELOAD_SYSTEM_NGINX="${RELOAD_SYSTEM_NGINX:-true}"

# 預設沿用 Amazon 主機所需的 sudo；本機測試可指定 DEPLOY_SUDO=""。
DEPLOY_SUDO="${DEPLOY_SUDO-sudo}"
run_privileged() {
    if [ -n "$DEPLOY_SUDO" ]; then
        "$DEPLOY_SUDO" "$@"
    else
        "$@"
    fi
}

# 在讀取 active upstream 前先確認部署掛載完整，讓 CI 錯誤訊息指出真正缺少的檔案。
if [ ! -d "$DEPLOY_ROOT" ]; then
    echo "錯誤: 部署根目錄不存在: $DEPLOY_ROOT" >&2
    exit 1
fi
if [ ! -f "$CONFIG_FILE" ]; then
    echo "錯誤: active upstream 設定不存在: $CONFIG_FILE" >&2
    exit 1
fi

# 移動到部署根目錄
cd "$DEPLOY_ROOT"

# 從 active-upstream.conf 提取當前的後端容器名稱
CURRENT_SERVICE=$(awk '/set \$active_backend/ {print $3}' "$CONFIG_FILE" | tr -d ';' | head -1)

echo "當前配置的 service 為: $CURRENT_SERVICE"

NEW_APP_ENV="green"
NEW_PORT="$GREEN_PORT"
# 若 CURRENT_SERVICE 中找不到 blue 的字串，本次就以 blue 為 nginx 名稱部署應用程式
if [[ $CURRENT_SERVICE != *"blue"* ]]; then
    NEW_APP_ENV="blue"
    NEW_PORT="$BLUE_PORT"
fi

echo "NEW_APP_ENV: $NEW_APP_ENV"
NEW_FOLDER="$APP_NAME_PREFIX-$NEW_APP_ENV"
NEW_PROJECT_NAME="$APP_NAME_PREFIX-$NEW_APP_ENV"
NEW_NGINX_NAME="$APP_NAME_PREFIX-nginx-$NEW_APP_ENV"
DEPLOY_DIR="$DEPLOY_ROOT/$NEW_FOLDER"

# Jenkins 可能未提供 USER/GROUP，避免 set -u 造成 unbound variable。
DEPLOY_USER="${SUDO_USER:-${USER:-$(id -un)}}"
DEPLOY_GROUP="${SUDO_GROUP:-${GROUP:-$(id -gn "$DEPLOY_USER")}}"

# 若目錄已存在，先停止殘留容器再刪除目錄
if [ -d "$DEPLOY_DIR" ]; then
    echo "偵測到殘留目錄 $NEW_FOLDER，先停止容器..."
    cd "$DEPLOY_DIR" && make down || true
    cd "$DEPLOY_ROOT"
    run_privileged rm -rf "$DEPLOY_DIR"
fi

# 下載專案
git clone --branch "$APP_BRANCH" --single-branch "$GITHUB_REPO" "$DEPLOY_DIR"

# copy env
cp "$APP_ENV_FILE" "$DEPLOY_DIR/.env"
cp "$DOCKER_ENV_FILE" "$DEPLOY_DIR/.docker/compose/.env"

# Blue/Green 只切換 Compose project、container 與 port；兩邊共用同一個
# versioned runtime image，避免每次部署重新編譯 apt/PECL layer。
sed -i.bak "s/^COMPOSE_PROJECT_NAME=.*/COMPOSE_PROJECT_NAME=$NEW_PROJECT_NAME/" "$DEPLOY_DIR/.docker/compose/.env"
sed -i.bak "s/^NGINX_NAME=.*/NGINX_NAME=$NEW_NGINX_NAME/" "$DEPLOY_DIR/.docker/compose/.env"
# 替換並修改 docker env 的 port
sed -i.bak "s/^APP_PORT=.*/APP_PORT=$NEW_PORT/" "$DEPLOY_DIR/.docker/compose/.env"
# 刪除 sed 產生的備份檔
rm -f "$DEPLOY_DIR/.docker/compose/.env.bak"

# 啟動容器
echo "啟動新容器: 'cd $NEW_FOLDER && make deploy-up'"
cd "$DEPLOY_DIR"
make deploy-up
make composer-install

# 調整整個新部署目錄權限
echo "調整新部署目錄整體權限..."
run_privileged chown -R "$DEPLOY_USER":"$DEPLOY_GROUP" "$DEPLOY_DIR"
run_privileged chmod -R a+rwX "$DEPLOY_DIR"

# 檢查容器是否啟動成功(回應200)
HEALTH_STATUS=""
RETRY_COUNT=1

# 若回應不是 "ok" 則3秒後重試，最多重試10次
while [ "$HEALTH_STATUS" != "ok" ] && [ $RETRY_COUNT -lt 11 ]; do
    echo "嘗試第 $RETRY_COUNT 次檢測 $NEW_PORT port 的健康狀態..."
    
    # Jenkins 使用共用 Docker 網路的 container DNS；本機則走 published port。
    if [ "$HEALTHCHECK_MODE" = "published" ]; then
        HEALTHCHECK_URL="http://127.0.0.1:$NEW_PORT/api/health"
    else
        HEALTHCHECK_URL="http://$NEW_NGINX_NAME/api/health"
    fi
    HEALTH_STATUS=$(curl --silent --show-error --max-time 5 "$HEALTHCHECK_URL" 2>/dev/null || echo "error")
    
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

# 執行單元測試
echo "執行單元測試..."
# 先移除測試用 MySQL 容器, 避免測試失敗後殘留容器影響後續部署
cd "$DEPLOY_DIR" && make test-db-down
# 啟動測試用 MySQL 容器
cd "$DEPLOY_DIR" && make test-db-up
# 建立測試 database 並執行遷移
cd "$DEPLOY_DIR" && make ensure-testing-db
# 執行測試, 若測試失敗，則停止測試 MySQL 與新容器並退出
cd "$DEPLOY_DIR" && make test || {
    echo "錯誤: 單元測試失敗，停止新容器並退出..."
    make test-db-down
    make down
    exit 1
}
# 測試完畢，移除測試用 MySQL 容器
cd "$DEPLOY_DIR" && make test-db-down

# 切換 nginx 配置
sed -i.bak "s/set \\\$active_backend .*/set \\\$active_backend $NEW_NGINX_NAME;/" "$CONFIG_FILE"
rm -f "$CONFIG_FILE.bak"

# 重載 nginx（等待容器就緒）。本機隔離測試可只驗證切換檔。
if [ "$RELOAD_SYSTEM_NGINX" = "true" ]; then
    echo "等待系統 nginx 容器就緒..."
    until cd "$SYSTEM_FOLDER/system" && run_privileged docker compose exec -T nginx true 2>/dev/null; do
        echo "nginx 容器尚未就緒，等待 2 秒..."
        sleep 2
    done
    cd "$SYSTEM_FOLDER/system" && run_privileged docker compose exec -T nginx nginx -s reload
else
    echo "略過系統 nginx reload（隔離測試模式）"
fi

# 停止舊容器
if [ "$NEW_APP_ENV" == "green" ]; then
    OLD_FOLDER="$DEPLOY_ROOT/$APP_NAME_PREFIX-blue"
else
    OLD_FOLDER="$DEPLOY_ROOT/$APP_NAME_PREFIX-green"
fi

if [ -d "$OLD_FOLDER" ]; then
    echo "停止舊容器: cd $OLD_FOLDER && make down"
    cd "$OLD_FOLDER" && make down
    # 刪除舊目錄
    run_privileged rm -rf "$OLD_FOLDER"
else
    echo "舊目錄 $OLD_FOLDER 不存在，跳過停止舊容器"
fi

# 若部署根目錄下的 amanda-blog 存在（首次部署前的原始目錄），則停止並刪除
if [ -d "$DEPLOY_ROOT/$APP_NAME_PREFIX" ]; then
    echo "偵測到原始 $APP_NAME_PREFIX 目錄，停止容器並刪除..."
    cd "$DEPLOY_ROOT/$APP_NAME_PREFIX" && make down || true
    run_privileged rm -rf "$DEPLOY_ROOT/$APP_NAME_PREFIX"
    echo "原始 $APP_NAME_PREFIX 目錄已刪除"
fi

echo "部署完成，新的專案已經啟動"
