# =============================================================================
# TARGETS
# =============================================================================

#### Test

.PHONY: test

test: .logo ## Runs the full composer ci:test suite as www-data. Usage: make test
	${COMPOSE_BIN} exec -u www-data phpfpm composer ci:test
