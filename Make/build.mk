# =============================================================================
# TARGETS
# =============================================================================

#### Build & Deployment

.PHONY: build rebuild

build: .logo ## Builds/Updates the used docker images and restarts containers.
	$(COMPOSE_BIN) build --pull
	$(COMPOSE_BIN) up -d

rebuild: .logo ## Forces a complete rebuild of all docker images (no cache) and restarts containers.
	$(COMPOSE_BIN) down -v
	$(COMPOSE_BIN) build --pull --no-cache
	$(COMPOSE_BIN) up -d
