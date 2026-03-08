COMPOSE_DIR = .docker/compose
# 指定 compose 讀檔時載入的 `compose.yaml` 檔案
COMPOSE_FILES = -f compose.yaml
APP_SERVICE = service
MYSQL_SERVICE = mysql
REDIS_SERVICE = redis
COMPOSE_CMD = cd $(COMPOSE_DIR) && docker compose $(COMPOSE_FILES)
TEST_COMPOSE_CMD = cd $(COMPOSE_DIR) && docker compose -f compose.test.yaml

### Docker Commands ###
# Build the Docker image
.PHONY: build
build:
	$(COMPOSE_CMD) build

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
	$(COMPOSE_CMD) exec $(APP_SERVICE) composer install

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