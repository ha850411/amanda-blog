#!/bin/bash
# ============================================================
# MySQL 每日備份並上傳至 S3，自動清除 3 天前的舊備份
# 排程：每天 00:00 執行
# Crontab 設定：0 0 * * * /path/to/crontab/mysql-backup.sh >> /var/log/mysql-backup.log 2>&1
# ============================================================

set -euo pipefail

# ────────────────────────────────────────
# 載入 .env（與腳本同目錄）
# ────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"

if [[ -f "${ENV_FILE}" ]]; then
    # 忽略空行與註解，安全載入
    set -o allexport
    # shellcheck source=/dev/null
    source "${ENV_FILE}"
    set +o allexport
else
    echo "[WARN] 找不到 ${ENV_FILE}，將使用系統環境變數" >&2
fi

# ────────────────────────────────────────
# 環境變數設定
# ────────────────────────────────────────
MYSQL_CONTAINER="${MYSQL_CONTAINER:-amanda-blog-mysql}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:?請設定 MYSQL_PASSWORD 環境變數}"
MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"

S3_BUCKET="${S3_BUCKET:?請設定 S3_BUCKET 環境變數}"   # 例如 s3://my-bucket/mysql-backups
S3_REGION="${S3_REGION:-ap-northeast-1}"
AWS_PROFILE="${AWS_PROFILE:-}"                         # 留空則使用預設 credential

BACKUP_DIR="${BACKUP_DIR:-/tmp/mysql-backups}"
RETAIN_DAYS=3

# ────────────────────────────────────────
# 初始化
# ────────────────────────────────────────
DATE=$(date +"%Y-%m-%d_%H-%M-%S")
LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')]"

log() {
    echo "${LOG_PREFIX} $*"
}

error_exit() {
    echo "${LOG_PREFIX} [ERROR] $*" >&2
    exit 1
}

mkdir -p "${BACKUP_DIR}"

# ────────────────────────────────────────
# 取得所有資料庫（排除系統庫）
# ────────────────────────────────────────
log "開始取得 MySQL 資料庫清單..."

MYSQL_CMD="mysql -h ${MYSQL_HOST} -P ${MYSQL_PORT} -u ${MYSQL_USER} -p${MYSQL_PASSWORD}"

DATABASES=$(${MYSQL_CMD} --silent --skip-column-names -e \
    "SHOW DATABASES;" 2>/dev/null \
    | grep -Ev '^(information_schema|performance_schema|mysql|sys)$') \
    || error_exit "無法連接 MySQL，請確認連線資訊"

if [[ -z "${DATABASES}" ]]; then
    log "找不到任何使用者資料庫，結束備份。"
    exit 0
fi

# ────────────────────────────────────────
# 備份每個資料庫
# ────────────────────────────────────────
BACKUP_FILES=()

for DB in ${DATABASES}; do
    FILENAME="${DB}_${DATE}.sql.gz"
    FILEPATH="${BACKUP_DIR}/${FILENAME}"

    log "備份資料庫：${DB} -> ${FILEPATH}"

    mysqldump \
        -h "${MYSQL_HOST}" \
        -P "${MYSQL_PORT}" \
        -u "${MYSQL_USER}" \
        -p"${MYSQL_PASSWORD}" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --set-gtid-purged=OFF \
        "${DB}" 2>/dev/null \
        | gzip -9 > "${FILEPATH}" \
        || error_exit "備份 ${DB} 失敗"

    BACKUP_FILES+=("${FILEPATH}")
    log "備份完成：${FILENAME}"
done

# ────────────────────────────────────────
# 上傳至 S3
# ────────────────────────────────────────
log "開始上傳備份至 S3：${S3_BUCKET}"

AWS_ARGS=(--region "${S3_REGION}")
[[ -n "${AWS_PROFILE}" ]] && AWS_ARGS+=(--profile "${AWS_PROFILE}")

for FILEPATH in "${BACKUP_FILES[@]}"; do
    FILENAME=$(basename "${FILEPATH}")
    S3_PATH="${S3_BUCKET}/${FILENAME}"

    log "上傳：${FILENAME} -> ${S3_PATH}"

    aws s3 cp "${FILEPATH}" "${S3_PATH}" "${AWS_ARGS[@]}" \
        || error_exit "上傳 ${FILENAME} 至 S3 失敗"

    log "上傳成功：${S3_PATH}"

    # 上傳後刪除本地暫存
    rm -f "${FILEPATH}"
    log "已刪除本地暫存：${FILEPATH}"
done

# ────────────────────────────────────────
# 刪除 S3 上超過 3 天的舊備份
# ────────────────────────────────────────
log "清除 S3 上 ${RETAIN_DAYS} 天前的舊備份..."

CUTOFF_DATE=$(date -d "-${RETAIN_DAYS} days" +"%Y-%m-%dT%H:%M:%S")

aws s3 ls "${S3_BUCKET}/" "${AWS_ARGS[@]}" \
    | awk '{print $1" "$2" "$4}' \
    | while read -r FILE_DATE FILE_TIME FILENAME; do
        FILE_DATETIME="${FILE_DATE}T${FILE_TIME}"
        if [[ "${FILE_DATETIME}" < "${CUTOFF_DATE}" ]] && [[ "${FILENAME}" == *.sql.gz ]]; then
            STALE_PATH="${S3_BUCKET}/${FILENAME}"
            log "刪除過期備份：${STALE_PATH}（檔案時間：${FILE_DATETIME}）"
            aws s3 rm "${STALE_PATH}" "${AWS_ARGS[@]}" \
                || log "[WARN] 刪除失敗：${STALE_PATH}"
        fi
    done

log "備份流程全部完成。"
