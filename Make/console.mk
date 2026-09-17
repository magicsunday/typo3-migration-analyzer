# =============================================================================
# Variables
# =============================================================================

# Never type a literal "VAR=..." argument on any `make` invocation in this
# repo. GNU Make unconditionally re-materializes a command-line "VAR=..."
# override into MAKEFLAGS and re-expands any Make function/variable syntax
# ("$(...)") embedded in it as soon as a real recipe runs, in any makefile —
# no $(shell ...) call anywhere is needed for that (confirmed by direct
# reproduction; plain shell metacharacters like backticks or semicolons in
# the same override are NOT re-expanded by this mechanism, only Make's own
# "$(...)" syntax is). In this repo specifically, `make -n` and a
# nonexistent target aren't safe either, because the root Makefile computes
# COMPOSE_BIN via an immediately-evaluated $(shell ...) call, which forces
# the same materialization while parsing the Makefile itself, before any
# goal is even resolved (also confirmed by direct reproduction; a makefile
# with no $(shell ...) call anywhere does NOT trigger under -n or for a
# nonexistent target, only once a real recipe actually runs). SCAN_SOURCE is
# therefore extracted from $(MAKECMDGOALS) instead (a plain positional
# argument, immune to this mechanism), and must stay referenced only via
# the exported shell variable $$SCAN_SOURCE below, never spliced in as
# $(SCAN_SOURCE) inside a recipe line — that would hand attacker-controlled
# text to the shell's own command substitution (where backticks/semicolons
# WOULD matter).
#
# wordlist (not word 2) so a single quoted argument containing an embedded
# space (e.g. a WSL2 host path under /mnt/c/Users/<First Last>/...) is
# rejoined instead of silently truncated at its first space.
override SCAN_SOURCE := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))

# Format is selected by which target was invoked (word 1 of MAKECMDGOALS).
# ScanExtensionCommand validates --format against a fixed allowlist anyway,
# so a malformed value here can only produce a clean "Invalid format" error.
override SCAN_FORMAT_FLAG := $(if $(filter scan-%,$(word 1,$(MAKECMDGOALS))),--format=$(subst scan-,,$(word 1,$(MAKECMDGOALS))))

export SCAN_SOURCE
export SCAN_FORMAT_FLAG

# =============================================================================
# TARGETS
# =============================================================================

#### Console

.PHONY: scan scan-json scan-csv scan-markdown

# Each target needs its own help-visible "## ..." line (make help's grep is
# one-target-per-line); the shared guard + recipe below is attached
# separately so the four targets don't duplicate it.
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
