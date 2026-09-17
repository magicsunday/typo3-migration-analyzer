# =============================================================================
# Variables
# =============================================================================

# Do NOT accept "SOURCE=..." as a make override here — it is shell-injectable
# via GNU Make's MAKEFLAGS re-materialization of command-line var overrides
# (triggered by this file's own COMPOSE_BIN detection). See commit 5efd85a1
# for the full mechanism and reproduction. A plain positional argument via
# $(MAKECMDGOALS) is not subject to it.
# wordlist (not word 2) so a single quoted argument containing an embedded
# space (e.g. a WSL2 host path under /mnt/c/Users/<First Last>/...) is
# rejoined instead of silently truncated at its first space.
#
# Residual, unfixable-from-here risk: this only protects the documented
# "make scan <path-or-git-url>" interface. Typing an EXPLICIT "VAR=..."
# token yourself on ANY `make` invocation in this repo (any variable name,
# any target) still triggers the same MAKEFLAGS-materialization side effect
# before this file's own override/recipe logic ever runs — that happens
# during Make's own command-line parsing, which no target-level code can
# intercept. Never type a "VAR=..." argument containing untrusted text
# (e.g. pasted from an issue/PR) into a `make` command in this repo.
override SCAN_SOURCE := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))

# The invoked target name itself (scan / scan-json / scan-csv / scan-markdown)
# selects the report format; it is a fixed, developer-authored string picked
# from the target list below, never user-supplied text, so this is not
# subject to the SOURCE-argument risk documented above.
override SCAN_FORMAT_FLAG := $(if $(filter scan-%,$(word 1,$(MAKECMDGOALS))),--format=$(subst scan-,,$(word 1,$(MAKECMDGOALS))))

export SCAN_SOURCE
export SCAN_FORMAT_FLAG

# =============================================================================
# TARGETS
# =============================================================================

#### Console

.PHONY: scan scan-json scan-csv scan-markdown

# Each target below gets its own help-visible "## ..." line (make help greps
# one-target-per-line); the shared guard + docker compose recipe is attached
# separately so all four run the identical logic.
scan: ## Scans a TYPO3 extension for deprecated API usage (text). Usage: make scan <path-or-git-url>
scan-json: ## Scans a TYPO3 extension and prints the findings as JSON. Usage: make scan-json <path-or-git-url>
scan-csv: ## Scans a TYPO3 extension and prints the findings as CSV. Usage: make scan-csv <path-or-git-url>
scan-markdown: ## Scans a TYPO3 extension and prints the findings as Markdown. Usage: make scan-markdown <path-or-git-url>

scan scan-json scan-csv scan-markdown: .logo
	@if [ -z "$$SCAN_SOURCE" ]; then \
		echo "Usage: make $@ <path-or-git-url>"; \
		exit 1; \
	fi
	${COMPOSE_BIN} exec -u www-data -e SCAN_SOURCE -e SCAN_FORMAT_FLAG phpfpm sh -c 'exec bin/console scan:extension "$$SCAN_SOURCE" $$SCAN_FORMAT_FLAG'
