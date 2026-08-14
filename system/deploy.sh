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
DRAIN_TIMEOUT_SECONDS="${DRAIN_TIMEOUT_SECONDS:-5}"

case "$DRAIN_TIMEOUT_SECONDS" in
    ''|*[!0-9]*)
        echo "錯誤: DRAIN_TIMEOUT_SECONDS 必須是非負整數" >&2
        exit 1
        ;;
esac

case "$HEALTHCHECK_MODE" in
    container|published) ;;
    *)
        echo "錯誤: HEALTHCHECK_MODE 必須是 container 或 published" >&2
        exit 1
        ;;
esac

case "$RELOAD_SYSTEM_NGINX" in
    true|false) ;;
    *)
        echo "錯誤: RELOAD_SYSTEM_NGINX 必須是 true 或 false" >&2
        exit 1
        ;;
esac

# 預設沿用 Amazon 主機所需的 sudo；本機測試可指定 DEPLOY_SUDO=""。
DEPLOY_SUDO="${DEPLOY_SUDO-sudo}"
run_privileged() {
    if [ -n "$DEPLOY_SUDO" ]; then
        "$DEPLOY_SUDO" "$@"
    else
        "$@"
    fi
}

app_compose() {
    (
        cd "$DEPLOY_DIR/.docker/compose"
        # Keep application Compose commands consistent with the Makefile
        # commands above. Jenkins runs this script non-interactively, so a
        # sudo wrapper here would require a password during migration.
        env "RUNTIME_IMAGE_TAG=$RUNTIME_IMAGE_TAG" docker compose "$@"
    )
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
NEW_NETWORK_NAME="$APP_NAME_PREFIX-web-network-$NEW_APP_ENV"
DEPLOY_DIR="$DEPLOY_ROOT/$NEW_FOLDER"

if [ "$NEW_APP_ENV" = "green" ]; then
    OLD_APP_ENV="blue"
else
    OLD_APP_ENV="green"
fi

OLD_FOLDER="$DEPLOY_ROOT/$APP_NAME_PREFIX-$OLD_APP_ENV"
OLD_NETWORK_NAME="$APP_NAME_PREFIX-web-network-$OLD_APP_ENV"

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
# Blue/Green 使用獨立 network，避免兩套 PHP 都以 `service` alias 出現在同一 DNS zone。
sed -i.bak "s/^NETWORK_NAME=.*/NETWORK_NAME=$NEW_NETWORK_NAME/" "$DEPLOY_DIR/.docker/compose/.env"
# 刪除 sed 產生的備份檔
rm -f "$DEPLOY_DIR/.docker/compose/.env.bak"

if ! grep -Fxq "NETWORK_NAME=$NEW_NETWORK_NAME" "$DEPLOY_DIR/.docker/compose/.env"; then
    echo "錯誤: Docker env 缺少可替換的 NETWORK_NAME" >&2
    exit 1
fi

# Application Compose 將 web-network 宣告為 external，由部署流程管理每個顏色的網路。
if ! run_privileged docker network inspect "$NEW_NETWORK_NAME" >/dev/null 2>&1; then
    run_privileged docker network create \
        --label com.amanda-blog.role=deployment \
        "$NEW_NETWORK_NAME" >/dev/null
fi

SYSTEM_NGINX_ID=""
JENKINS_ID=""

connect_network_if_needed() {
    local network_name="$1"
    local container_id="$2"

    if ! run_privileged docker inspect \
        --format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' \
        "$container_id" | grep -Fxq "$network_name"; then
        run_privileged docker network connect "$network_name" "$container_id"
    fi
}

if [ "$RELOAD_SYSTEM_NGINX" = "true" ]; then
    SYSTEM_NGINX_ID=$(cd "$SYSTEM_FOLDER/system" && run_privileged docker compose ps -q nginx)
    if [ -z "$SYSTEM_NGINX_ID" ]; then
        echo "錯誤: 找不到 system nginx 容器" >&2
        exit 1
    fi
    connect_network_if_needed "$NEW_NETWORK_NAME" "$SYSTEM_NGINX_ID"
fi

if [ "$HEALTHCHECK_MODE" = "container" ]; then
    JENKINS_ID=$(cd "$SYSTEM_FOLDER/system" && run_privileged docker compose ps -q jenkins)
    if [ -z "$JENKINS_ID" ]; then
        echo "錯誤: container health check 模式找不到 Jenkins 容器" >&2
        exit 1
    fi
    connect_network_if_needed "$NEW_NETWORK_NAME" "$JENKINS_ID"
fi

# 啟動容器
echo "啟動新容器: 'cd $NEW_FOLDER && make deploy-up'"
cd "$DEPLOY_DIR"
RUNTIME_IMAGE_TAG=$(make runtime-tag)
export RUNTIME_IMAGE_TAG
echo "使用 runtime image tag: $RUNTIME_IMAGE_TAG"
make deploy-up
make composer-install
make runtime-permissions

# 正式資料庫由 blue/green 版本共用。先套用新版本的 migration，確認成功後
# 才繼續 health check、測試與切換流量；migration 失敗時保留舊版本在線。
echo "執行正式資料庫 migration..."
if ! app_compose exec -T service php artisan migrate --force; then
    echo "錯誤: 正式資料庫 migration 失敗，停止新容器並保留舊版本..." >&2
    cd "$DEPLOY_DIR" && make down || true
    exit 1
fi

# 新版本的 queue worker 可能在 migration 完成前因找不到 jobs table 而退出，
# migration 成功後重新建立並重啟，確保 LINE webhook job 能正常消費。
echo "重新啟動 queue worker..."
app_compose up -d queue
app_compose restart queue

# 檢查容器是否啟動成功(回應200)
HEALTH_STATUS=""
RETRY_COUNT=1

# 若回應不是 "ok" 則3秒後重試，最多重試10次
if [ "$HEALTHCHECK_MODE" = "published" ]; then
    HEALTHCHECK_BASE_URL="http://127.0.0.1:$NEW_PORT"
else
    HEALTHCHECK_BASE_URL="http://$NEW_NGINX_NAME"
fi
HEALTHCHECK_URL="$HEALTHCHECK_BASE_URL/api/health"

while [ "$HEALTH_STATUS" != "ok" ] && [ $RETRY_COUNT -lt 11 ]; do
    echo "嘗試第 $RETRY_COUNT 次檢測 $NEW_PORT port 的健康狀態..."

    # Jenkins 使用新顏色 network 的 container DNS；本機則走 published port。
    HEALTH_STATUS=$(curl --fail --silent --show-error \
        --connect-timeout 2 --max-time 5 "$HEALTHCHECK_URL" 2>/dev/null || echo "error")
    
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

# /api/health 不會查詢資料庫；預熱實際首頁，避免第一位使用者承擔初始化成本。
echo "預熱新環境首頁..."
if ! curl --fail --silent --show-error \
    --connect-timeout 2 --max-time 10 "$HEALTHCHECK_BASE_URL/" >/dev/null; then
    echo "錯誤: 新環境首頁無法正常回應，不切換流量" >&2
    cd "$DEPLOY_DIR" && make down || true
    exit 1
fi

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

# Artisan tests are executed as root inside the container and can recreate
# daily log/cache files as root:root. Restore PHP-FPM ownership before traffic
# is switched to this release.
cd "$DEPLOY_DIR" && make runtime-permissions

# 切換 nginx 配置
sed -i.bak "s/set \\\$active_backend .*/set \\\$active_backend $NEW_NGINX_NAME;/" "$CONFIG_FILE"

# 重載 nginx（等待容器就緒）。本機隔離測試可只驗證切換檔。
DRAIN_COMPLETE="true"
if [ "$RELOAD_SYSTEM_NGINX" = "true" ]; then
    echo "等待系統 nginx 容器就緒..."
    until cd "$SYSTEM_FOLDER/system" && run_privileged docker compose exec -T nginx true 2>/dev/null; do
        echo "nginx 容器尚未就緒，等待 2 秒..."
        sleep 2
    done

    if ! (cd "$SYSTEM_FOLDER/system" && run_privileged docker compose exec -T nginx nginx -t); then
        echo "錯誤: 新的 Nginx 設定驗證失敗，還原原本 upstream" >&2
        mv "$CONFIG_FILE.bak" "$CONFIG_FILE"
        exit 1
    fi

    # reload 前記住目前 worker；reload 後只等待這批舊 worker 完成既有請求。
    if ! OLD_WORKER_PIDS=$(run_privileged docker exec "$SYSTEM_NGINX_ID" sh -c '
        master_pid=$(cat /var/run/nginx.pid)
        cat "/proc/$master_pid/task/$master_pid/children"
    '); then
        echo "錯誤: 無法取得 reload 前的 Nginx worker，還原原本 upstream" >&2
        mv "$CONFIG_FILE.bak" "$CONFIG_FILE"
        exit 1
    fi

    if ! (cd "$SYSTEM_FOLDER/system" && run_privileged docker compose exec -T nginx nginx -s reload); then
        echo "錯誤: Nginx reload 失敗，還原原本 upstream" >&2
        mv "$CONFIG_FILE.bak" "$CONFIG_FILE"
        exit 1
    fi
    rm -f "$CONFIG_FILE.bak"

    old_nginx_workers_running() {
        local pid

        for pid in $OLD_WORKER_PIDS; do
            if run_privileged docker exec "$SYSTEM_NGINX_ID" test -d "/proc/$pid"; then
                return 0
            fi
        done

        return 1
    }

    if [ -n "$OLD_WORKER_PIDS" ]; then
        echo "流量已切換，等待舊 Nginx worker 完成既有請求..."
        DRAIN_DEADLINE=$(($(date +%s) + DRAIN_TIMEOUT_SECONDS))

        while old_nginx_workers_running; do
            if [ "$(date +%s)" -ge "$DRAIN_DEADLINE" ]; then
                DRAIN_COMPLETE="false"
                break
            fi
            sleep 0.2
        done
    fi
else
    echo "略過系統 nginx reload（隔離測試模式）"
    rm -f "$CONFIG_FILE.bak"
fi

# 停止舊容器
if [ "$DRAIN_COMPLETE" != "true" ]; then
    echo "警告: 舊 Nginx worker 在 ${DRAIN_TIMEOUT_SECONDS} 秒內尚未退出" >&2
    echo "為避免中斷既有請求，本次保留舊環境，下一次部署前再回收" >&2
elif [ -d "$OLD_FOLDER" ]; then
    echo "停止舊容器: cd $OLD_FOLDER && make down"
    cd "$OLD_FOLDER" && make down
    # 刪除舊目錄
    run_privileged rm -rf "$OLD_FOLDER"
else
    echo "舊目錄 $OLD_FOLDER 不存在，跳過停止舊容器"
fi

# 舊應用停止後才中斷 system 容器並回收舊顏色 network。
if [ "$DRAIN_COMPLETE" = "true" ] && run_privileged docker network inspect "$OLD_NETWORK_NAME" >/dev/null 2>&1; then
    if [ -n "$SYSTEM_NGINX_ID" ]; then
        run_privileged docker network disconnect -f "$OLD_NETWORK_NAME" "$SYSTEM_NGINX_ID" || true
    fi
    if [ -n "$JENKINS_ID" ]; then
        run_privileged docker network disconnect -f "$OLD_NETWORK_NAME" "$JENKINS_ID" || true
    fi
    run_privileged docker network rm "$OLD_NETWORK_NAME" >/dev/null || true
fi

# 若部署根目錄下的 amanda-blog 存在（首次部署前的原始目錄），則停止並刪除
if [ -d "$DEPLOY_ROOT/$APP_NAME_PREFIX" ]; then
    echo "偵測到原始 $APP_NAME_PREFIX 目錄，停止容器並刪除..."
    cd "$DEPLOY_ROOT/$APP_NAME_PREFIX" && make down || true
    run_privileged rm -rf "$DEPLOY_ROOT/$APP_NAME_PREFIX"
    echo "原始 $APP_NAME_PREFIX 目錄已刪除"
fi

echo "部署完成，新的專案已經啟動"
