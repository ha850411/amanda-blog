#!/usr/bin/env bash
set -euo pipefail

# 設為台灣時區，確保排程和日誌時間正確
sudo timedatectl set-timezone Asia/Taipei

# 安裝基本工具
sudo dnf update -y
sudo dnf install -y git docker make
sudo dnf install -y https://dev.mysql.com/get/mysql84-community-release-el9-1.noarch.rpm
sudo dnf install -y mysql-community-client

# 安裝 crontab（Amazon Linux 2023 預設已安裝 cronie）
if ! command -v crontab >/dev/null 2>&1; then
	sudo dnf install -y cronie
fi

# Amazon Linux 2023 常見為 curl-minimal，避免與 curl 套件衝突
if ! command -v curl >/dev/null 2>&1; then
	sudo dnf install -y curl-minimal
fi

# 啟動 crond 服務
sudo systemctl enable --now crond

# 啟動 Docker 服務
sudo systemctl enable --now docker

# 為 crontab 底下的所有程式增加執行權限
sudo chmod +x /workspace/amanda-blog-system

# 讀取宿主機 docker socket 的 GID，供 Jenkins 容器 group_add 使用
DOCKER_GID="$(stat -c '%g' /var/run/docker.sock)"

# 建立 workspace 資料夾
sudo mkdir -p /workspace
sudo chown -R "$USER":"$USER" /workspace

# 安裝 Docker Compose plugin（AWS/Linux 建議做法）
if sudo dnf list -q docker-compose-plugin >/dev/null 2>&1; then
	sudo dnf install -y docker-compose-plugin
else
	echo "docker-compose-plugin is not available in current repos, using fallback installation if needed."
fi

if ! docker compose version >/dev/null 2>&1; then
	echo "docker compose plugin not found, installing standalone compose binary..."

	ARCH=$(uname -m)
	if [ "$ARCH" = "x86_64" ]; then
		COMPOSE_ARCH="x86_64"
	elif [ "$ARCH" = "aarch64" ] || [ "$ARCH" = "arm64" ]; then
		COMPOSE_ARCH="aarch64"
	else
		echo "Unsupported architecture: $ARCH"
		exit 1
	fi

	DOCKER_COMPOSE_VERSION=$(curl -fsSL -o /dev/null -w %{url_effective} https://github.com/docker/compose/releases/latest | awk -F/ '{print $NF}')
	sudo mkdir -p /usr/local/lib/docker/cli-plugins
	sudo curl -fL "https://github.com/docker/compose/releases/download/${DOCKER_COMPOSE_VERSION}/docker-compose-linux-${COMPOSE_ARCH}" -o /usr/local/lib/docker/cli-plugins/docker-compose
	sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
fi

docker compose version

# 確保 buildx 可用且版本足夠（compose build 需要 >= 0.17.0）
BUILDX_MIN_VERSION="0.17.0"

version_ge() {
	[ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n1)" = "$1" ]
}

BUILDX_VERSION="0.0.0"
if docker buildx version >/dev/null 2>&1; then
	BUILDX_VERSION=$(docker buildx version | awk '{print $2}' | sed 's/^v//')
fi

if ! version_ge "$BUILDX_VERSION" "$BUILDX_MIN_VERSION"; then
	echo "docker buildx $BUILDX_MIN_VERSION+ is required, installing/upgrading buildx..."

	if sudo dnf list -q docker-buildx-plugin >/dev/null 2>&1; then
		sudo dnf install -y docker-buildx-plugin
	fi

	BUILDX_VERSION="0.0.0"
	if docker buildx version >/dev/null 2>&1; then
		BUILDX_VERSION=$(docker buildx version | awk '{print $2}' | sed 's/^v//')
	fi

	if ! version_ge "$BUILDX_VERSION" "$BUILDX_MIN_VERSION"; then
		ARCH=$(uname -m)
		if [ "$ARCH" = "x86_64" ]; then
			BUILDX_ARCH="amd64"
		elif [ "$ARCH" = "aarch64" ] || [ "$ARCH" = "arm64" ]; then
			BUILDX_ARCH="arm64"
		else
			echo "Unsupported architecture for buildx: $ARCH"
			exit 1
		fi

		BUILDX_VERSION="v0.19.2"
		sudo mkdir -p /usr/local/lib/docker/cli-plugins
		sudo curl -fL "https://github.com/docker/buildx/releases/download/${BUILDX_VERSION}/buildx-${BUILDX_VERSION}.linux-${BUILDX_ARCH}" -o /usr/local/lib/docker/cli-plugins/docker-buildx
		sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-buildx
	fi
fi

docker buildx version

# 加入 docker 群組（已在前面執行過一次，但確保生效）
sudo usermod -aG docker "$USER"

# 同步 DOCKER_GID 到 system/.env，讓 docker compose 能只開給 Jenkins
SYSTEM_ENV_FILE="/workspace/amanda-blog-system/system/.env"
if [ -f "$SYSTEM_ENV_FILE" ]; then
	if grep -q '^DOCKER_GID=' "$SYSTEM_ENV_FILE"; then
		sudo sed -i "s/^DOCKER_GID=.*/DOCKER_GID=${DOCKER_GID}/" "$SYSTEM_ENV_FILE"
	else
		echo "DOCKER_GID=${DOCKER_GID}" | sudo tee -a "$SYSTEM_ENV_FILE" >/dev/null
	fi
	echo "Updated $SYSTEM_ENV_FILE with DOCKER_GID=${DOCKER_GID}"
else
	echo "Warning: $SYSTEM_ENV_FILE not found, skipped DOCKER_GID sync."
fi

# 設定 ll 指令為 ls -alF（避免重複寫入）
if ! grep -q "alias ll='ls -alF'" ~/.bashrc; then
	echo "alias ll='ls -alF'" >> ~/.bashrc
fi

echo "Done. Please log out and log back in to apply docker group changes."
echo "Then recreate Jenkins container so group_add picks up DOCKER_GID=${DOCKER_GID}."