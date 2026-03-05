COMPOSE_FILES = -f docker-compose.yml
COMPOSE_CMD = docker compose $(COMPOSE_FILES)

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

# reload nginx
nginx-reload:
	$(COMPOSE_CMD) exec nginx nginx -s reload