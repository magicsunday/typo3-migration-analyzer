# =============================================================================
# TARGETS
# =============================================================================

#### Console

.PHONY: scan

scan: .logo ## Scans a TYPO3 extension for deprecated API usage. Usage: make scan SOURCE=path/or/git-url [ARGS="--format=json --fail-on-findings"]
	@if [ -z "$(SOURCE)" ]; then \
		echo "Usage: make scan SOURCE=<path-or-git-url> [ARGS=\"--format=json --fail-on-findings\"]"; \
		exit 1; \
	fi
	${COMPOSE_BIN} exec -u www-data phpfpm bin/console scan:extension "$(SOURCE)" $(ARGS)
