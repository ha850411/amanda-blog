COMPOSE_DIR = .docker/compose
# 指定 compose 讀檔時載入的 `compose.yaml` 檔案
COMPOSE_FILES = -f compose.yaml
APP_SERVICE = service
MYSQL_SERVICE = mysql
REDIS_SERVICE = redis
COMPOSE_CMD = cd $(COMPOSE_DIR) && docker compose $(COMPOSE_FILES)
TEST_COMPOSE_CMD = cd $(COMPOSE_DIR) && docker compose -f compose.test.yaml

# Compose 會自行讀取此檔；Make 同步載入 image 相關值，以便先檢查本機 image。
-include $(COMPOSE_DIR)/.env
APP_IMAGE ?= amanda-blog-runtime
PHP_VERSION ?= 8.4
RUNTIME_IMAGE_TAG ?= php$(PHP_VERSION)-v3
RUNTIME_IMAGE := $(APP_IMAGE):$(RUNTIME_IMAGE_TAG)
RUNTIME_REGISTRY_IMAGE ?= ghcr.io/ha850411/amanda-blog-runtime
PUBLISH_RUNTIME_IMAGE := $(RUNTIME_REGISTRY_IMAGE):$(RUNTIME_IMAGE_TAG)
BUILD_CACHE_IMAGE ?= ghcr.io/ha850411/amanda-blog-runtime:buildcache-php$(PHP_VERSION)
BUILDX_BUILDER ?= amanda-blog-runtime-builder
# Amazon Linux EC2 預設以 x86_64 發布；Graviton 使用者可覆寫為 linux/arm64。
# 多架構發布必須使用已配置原生 nodes 或可靠 binfmt/QEMU 的 builder。
RUNTIME_PLATFORMS ?= linux/amd64
PHP_IMAGE ?= php:8.4-fpm-bookworm@sha256:c5fb7a0c02f4efe280691910c8b734995fa83598cdcf3115ef5dcb2e4617681c
COMPOSER_IMAGE ?= composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040
COMPOSER_INSTALL_FLAGS ?= --no-interaction --prefer-dist --no-progress
COMPOSER_CACHE_VOLUME ?= amanda-blog-composer-cache

### Docker Commands ###
# Build the Docker image
.PHONY: build
build:
	$(COMPOSE_CMD) build $(APP_SERVICE)

# Production deployment entrypoint. A runtime version is built at most once:
# use the local image first, then try the registry, and only then build locally.
.PHONY: runtime-ready
runtime-ready:
	@if docker image inspect "$(RUNTIME_IMAGE)" >/dev/null 2>&1; then \
		echo "Reuse runtime image: $(RUNTIME_IMAGE)"; \
	elif ( $(COMPOSE_CMD) pull $(APP_SERVICE) ); then \
		echo "Pulled runtime image: $(RUNTIME_IMAGE)"; \
	else \
		echo "Runtime image not found; building once: $(RUNTIME_IMAGE)"; \
		( $(COMPOSE_CMD) build $(APP_SERVICE) ); \
	fi

.PHONY: composer-cache-ready
composer-cache-ready:
	@if ! docker volume inspect "$(COMPOSER_CACHE_VOLUME)" >/dev/null 2>&1; then \
		echo "Create shared Composer cache volume: $(COMPOSER_CACHE_VOLUME)"; \
		docker volume create "$(COMPOSER_CACHE_VOLUME)" >/dev/null; \
	fi

.PHONY: deploy-up
deploy-up: runtime-ready composer-cache-ready
	$(COMPOSE_CMD) up -d --no-build

# Publish a versioned runtime image and a separate mode=max cache to the registry.
# Run docker login for the target registry before invoking this target.
.PHONY: runtime-publish
runtime-publish:
	@docker buildx inspect "$(BUILDX_BUILDER)" >/dev/null 2>&1 || \
		docker buildx create --name "$(BUILDX_BUILDER)" --driver docker-container
	docker buildx build \
		--builder "$(BUILDX_BUILDER)" \
		--platform "$(RUNTIME_PLATFORMS)" \
		--file .docker/Dockerfile \
		--tag "$(PUBLISH_RUNTIME_IMAGE)" \
		--build-arg "PHP_IMAGE=$(PHP_IMAGE)" \
		--build-arg "COMPOSER_IMAGE=$(COMPOSER_IMAGE)" \
		--cache-from "type=registry,ref=$(BUILD_CACHE_IMAGE)" \
		--cache-to "type=registry,ref=$(BUILD_CACHE_IMAGE),mode=max" \
		--push \
		.docker

# Start the services
.PHONY: up
up:
	$(COMPOSE_CMD) up -d

# Stop the services
down:
	$(COMPOSE_CMD) down

# Restart the services
restart:
	$(COMPOSE_CMD) down
	$(COMPOSE_CMD) up -d

### PHP Commands ###
# Run composer install
.PHONY: composer-install
composer-install:
	$(COMPOSE_CMD) exec $(APP_SERVICE) composer install $(COMPOSER_INSTALL_FLAGS)

# CLI commands in CI run as root and may create Laravel logs/cache files that
# PHP-FPM (www-data) cannot update. Restore runtime ownership before serving traffic.
.PHONY: runtime-permissions
runtime-permissions:
	$(COMPOSE_CMD) exec -T --user root $(APP_SERVICE) sh -c \
		'chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache'

# 啟動測試用 MySQL 容器（等待就緒）
.PHONY: test-db-up
test-db-up:
	$(TEST_COMPOSE_CMD) up -d
	$(TEST_COMPOSE_CMD) exec test-mysql mysqladmin ping -h 127.0.0.1 -u root -proot --wait=60 --silent

# 移除測試用 MySQL 容器與匿名 volume
.PHONY: test-db-down
test-db-down:
	$(TEST_COMPOSE_CMD) down -v

# 建立測試環境資料庫並執行遷移
.PHONY: ensure-testing-db
ensure-testing-db:
	$(COMPOSE_CMD) exec $(APP_SERVICE) php artisan db:ensure-databases --env=testing
	$(COMPOSE_CMD) exec $(APP_SERVICE) php artisan migrate --env=testing

# 重建測試環境資料庫並執行遷移
.PHONY: recreate-testing-db
recreate-testing-db:
	$(COMPOSE_CMD) exec $(APP_SERVICE) php artisan db:recreate-databases --env=testing
	$(COMPOSE_CMD) exec $(APP_SERVICE) php artisan migrate --env=testing

# Run php artisan test
.PHONY: test
test:
	$(COMPOSE_CMD) exec $(APP_SERVICE) php artisan test --env=testing --parallel

# 測試覆蓋率
.PHONY: test-coverage
test-coverage:
	$(COMPOSE_CMD) exec -e XDEBUG_MODE=coverage $(APP_SERVICE) php artisan test --coverage -d memory_limit=-1

# Run composer update
.PHONY: composer-update
composer-update:
	$(COMPOSE_CMD) exec $(APP_SERVICE) composer update

# Run php artisan migrate
.PHONY: migrate
migrate:
	$(COMPOSE_CMD) exec $(APP_SERVICE) php artisan migrate

.PHONY: migrate-test
migrate-test:
	$(COMPOSE_CMD) exec $(APP_SERVICE) php artisan migrate --env=testing


# Run php artisan migrate:refresh
.PHONY: migrate-refresh
migrate-refresh:
	$(COMPOSE_CMD) exec $(APP_SERVICE) php artisan migrate:refresh

# Run php artisan migrate:refresh --seed
.PHONY: migrate-refresh-seed
migrate-refresh-seed:
	$(COMPOSE_CMD) exec $(APP_SERVICE) php artisan migrate:refresh --seed

# generate env key
.PHONY: key-generate
key-generate:
	$(COMPOSE_CMD) exec $(APP_SERVICE) php artisan key:generate
