# =============================================================================
# TARGETS
# =============================================================================

#### Build & Deployment

.PHONY: build rebuild

build: .logo ## Builds/Updates the used docker images.
	# Add --no-cache to force rebuild
	$(COMPOSE_BIN) build --pull

rebuild: .logo ## Forces a complete rebuild of all docker images (no cache).
	$(COMPOSE_BIN) build --pull --no-cache
