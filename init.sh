# 安裝 git
sudo dnf update -y
sudo dnf install -y git docker
# 啟動 Docker 服務
sudo systemctl start docker
sudo systemctl enable docker
sudo usermod -aG docker $USER
# 建立 worksapce 資料夾
sudo mkdir /workspace
sudo chown -R $USER:$USER /workspace

# 取得最新版本的 Docker Compose
DOCKER_COMPOSE_VERSION=$(curl -fsSL -o /dev/null -w %{url_effective} https://github.com/docker/compose/releases/latest | awk -F/ '{print $NF}')
# 安裝 docker-compose
sudo curl -L "https://github.com/docker/compose/releases/download/${DOCKER_COMPOSE_VERSION}/docker-compose-linux-aarch64" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
docker-compose --version