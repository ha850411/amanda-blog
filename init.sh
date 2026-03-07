#!/usr/bin/env bash
set -euo pipefail

# 安裝基本工具
sudo dnf update -y
sudo dnf install -y git docker make

# Amazon Linux 2023 常見為 curl-minimal，避免與 curl 套件衝突
if ! command -v curl >/dev/null 2>&1; then
	sudo dnf install -y curl-minimal
fi

# 啟動 Docker 服務
sudo systemctl enable --now docker

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
sudo usermod -aG docker $USER
newgrp docker

# 設定 ll 指令為 ls -alF（避免重複寫入）
if ! grep -q "alias ll='ls -alF'" ~/.bashrc; then
	echo "alias ll='ls -alF'" >> ~/.bashrc
fi

source ~/.bashrc

echo "Done. Please log out and log back in to apply docker group changes."