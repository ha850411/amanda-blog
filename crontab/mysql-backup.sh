#!/bin/sh
# ============================================================
# MySQL 每日備份並上傳至 S3，自動清除 3 天前的舊備份
# 排程：每天 00:00 執行
# Crontab 設定：0 0 * * * /path/to/crontab/mysql-backup.sh >> /var/log/mysql-backup.log 2>&1
# 相容：sh / bash / dash
# ============================================================

set -eu

# ────────────────────────────────────────
# 載入 .env（與腳本同目錄）
# ────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"

if [ -f "${ENV_FILE}" ]; then
    set -a
    . "${ENV_FILE}"
    set +a
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

S3_BUCKET="${S3_BUCKET:?請設定 S3_BUCKET 環境變數}"
S3_REGION="${S3_REGION:-ap-northeast-1}"
AWS_PROFILE="${AWS_PROFILE:-}"                # 留空則使用 IAM Role / default credential

BACKUP_DIR="${BACKUP_DIR:-/tmp/mysql-backups}"
RETAIN_DAYS=3

# ────────────────────────────────────────
# AWS CLI wrapper（統一注入 region / profile）
# ────────────────────────────────────────
aws_cmd() {
    if [ -n "${AWS_PROFILE}" ]; then
        aws --region "${S3_REGION}" --profile "${AWS_PROFILE}" "$@"
    else
        # 用 env -u 移除 AWS_PROFILE 環境變數，避免空值被 AWS CLI 誤讀
        env -u AWS_PROFILE aws --region "${S3_REGION}" "$@"
    fi
}

# ────────────────────────────────────────
# 初始化
# ────────────────────────────────────────
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

error_exit() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] $*" >&2
    exit 1
}

DATE=$(date +"%Y-%m-%d_%H-%M-%S")
mkdir -p "${BACKUP_DIR}"

# ────────────────────────────────────────
# 取得所有資料庫（排除系統庫）
# ────────────────────────────────────────
log "開始取得 MySQL 資料庫清單..."

DATABASES=$(mysql \
    -h "${MYSQL_HOST}" \
    -P "${MYSQL_PORT}" \
    -u "${MYSQL_USER}" \
    -p"${MYSQL_PASSWORD}" \
    --silent --skip-column-names \
    -e "SHOW DATABASES;" 2>/dev/null \
    | grep -Ev '^(information_schema|performance_schema|mysql|sys)$') \
    || error_exit "無法連接 MySQL，請確認連線資訊"

if [ -z "${DATABASES}" ]; then
    log "找不到任何使用者資料庫，結束備份。"
    exit 0
fi

# ────────────────────────────────────────
# 備份每個資料庫
# ────────────────────────────────────────
BACKUP_FILES=""

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

    BACKUP_FILES="${BACKUP_FILES} ${FILEPATH}"
    log "備份完成：${FILENAME}"
done

# ────────────────────────────────────────
# 上傳至 S3
# ────────────────────────────────────────
log "開始上傳備份至 S3：${S3_BUCKET}"

for FILEPATH in ${BACKUP_FILES}; do
    FILENAME=$(basename "${FILEPATH}")
    S3_PATH="${S3_BUCKET}/${FILENAME}"

    log "上傳：${FILENAME} -> ${S3_PATH}"

    aws_cmd s3 cp "${FILEPATH}" "${S3_PATH}" \
        || error_exit "上傳 ${FILENAME} 至 S3 失敗"

    log "上傳成功：${S3_PATH}"

    rm -f "${FILEPATH}"
    log "已刪除本地暫存：${FILEPATH}"
done

# ────────────────────────────────────────
# 刪除 S3 上超過 3 天的舊備份
# ────────────────────────────────────────
log "清除 S3 上 ${RETAIN_DAYS} 天前的舊備份..."

CUTOFF_DATE=$(date -d "-${RETAIN_DAYS} days" +"%Y-%m-%dT%H:%M:%S")

aws_cmd s3 ls "${S3_BUCKET}/" \
    | awk '{print $1" "$2" "$4}' \
    | while read -r FILE_DATE FILE_TIME FILENAME; do
        FILE_DATETIME="${FILE_DATE}T${FILE_TIME}"
        case "${FILENAME}" in
            *.sql.gz)
                if [ "${FILE_DATETIME}" \< "${CUTOFF_DATE}" ]; then
                    STALE_PATH="${S3_BUCKET}/${FILENAME}"
                    log "刪除過期備份：${STALE_PATH}（檔案時間：${FILE_DATETIME}）"
                    aws_cmd s3 rm "${STALE_PATH}" \
                        || log "[WARN] 刪除失敗：${STALE_PATH}"
                fi
                ;;
        esac
    done

log "備份流程全部完成。"
