# =============================================================================
# Variables
# =============================================================================

# GNU Make materializes ANY command-line "VAR=value" override into MAKEFLAGS
# as soon as a single $(shell ...) call exists anywhere in the makefile (this
# root Makefile's own COMPOSE_BIN detection is one), and that materialization
# re-evaluates embedded "$(shell ...)" syntax in the override's value BEFORE
# any in-makefile sanitizing (override/value/export) gets a chance to run —
# confirmed by direct reproduction, not theoretical. A "make scan SOURCE=..."
# interface is therefore unsafe by construction in this Makefile. Extracting
# the source from $(MAKECMDGOALS) (a plain positional argument, matched by
# the root Makefile's own "%: @:" catch-all) sidesteps that mechanism
# entirely, since goal words are never subject to the same MAKEFLAGS
# re-materialization. `override … := $(word 2, …)` still guards against a
# user also happening to pass a literal "SCAN_SOURCE=..." override.
override SCAN_SOURCE := $(word 2,$(MAKECMDGOALS))

export SCAN_SOURCE

# =============================================================================
# TARGETS
# =============================================================================

#### Console

.PHONY: scan

scan: .logo ## Scans a TYPO3 extension for deprecated API usage. Usage: make scan <path-or-git-url>
	@if [ -z "$$SCAN_SOURCE" ]; then \
		echo "Usage: make scan <path-or-git-url>"; \
		exit 1; \
	fi
	${COMPOSE_BIN} exec -u www-data -e SCAN_SOURCE phpfpm sh -c 'exec bin/console scan:extension "$$SCAN_SOURCE"'
