# =============================================================================
# Variables
# =============================================================================

# Do NOT accept "SOURCE=..." as a make override here. Any explicit "VAR=..."
# token on a `make` command line (any variable name, any target, in ANY
# makefile, not just this one) is unconditionally re-materialized into
# MAKEFLAGS by GNU Make itself, which re-evaluates embedded "$(shell ...)"/
# backtick/semicolon syntax in the value as a side effect of that
# materialization — this happens during Make's own command-line parsing,
# before any target-level code (including this file's override/recipe
# logic) ever runs, so nothing here can intercept it. See commit 5efd85a1
# for the original reproduction (note: its own commit message and this
# comment's earlier revision both incorrectly implied this requires a
# $(shell ...) call elsewhere in the makefile; it does not, confirmed by
# reproducing it against a makefile containing no $(shell ...) call at
# all). A plain positional argument via $(MAKECMDGOALS) is not subject to
# this mechanism, which is why SCAN_SOURCE is extracted that way below.
#
# Residual, unfixable-from-here risk: this only protects the documented
# "make scan[-json|-csv|-markdown] <path-or-git-url>" interface. Typing an
# EXPLICIT "VAR=..." token yourself on ANY `make` invocation in this repo
# still triggers the injection above. Never type a "VAR=..." argument
# containing untrusted text (e.g. pasted from an issue/PR) into a `make`
# command in this repo.
#
# wordlist (not word 2) so a single quoted argument containing an embedded
# space (e.g. a WSL2 host path under /mnt/c/Users/<First Last>/...) is
# rejoined instead of silently truncated at its first space.
override SCAN_SOURCE := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))

# The report format is selected by which of the four targets below was
# invoked, not by separately-typed text, as long as exactly one goal is
# given (the documented usage). With multiple space-separated goals on one
# command line, $(word 1,$(MAKECMDGOALS)) is simply whatever was typed
# first, independent of which target's recipe actually executes, so this
# is not a hard guarantee against arbitrary $(word 1,...) content in that
# case. It stays safe regardless: ScanExtensionCommand validates --format
# against a fixed allowlist and rejects anything else, so a malformed
# SCAN_FORMAT_FLAG here can only produce a clean "Invalid format" error,
# never reach a shell as anything but inert env-var data.
override SCAN_FORMAT_FLAG := $(if $(filter scan-%,$(word 1,$(MAKECMDGOALS))),--format=$(subst scan-,,$(word 1,$(MAKECMDGOALS))))

export SCAN_SOURCE
export SCAN_FORMAT_FLAG

# =============================================================================
# TARGETS
# =============================================================================

#### Console

.PHONY: scan scan-json scan-csv scan-markdown

# Each target below gets its own help-visible "## ..." line (make help greps
# one-target-per-line and only strips a trailing " .logo", not an arbitrary
# prerequisite name — a tried "scan-json: scan" prerequisite-chain form
# leaked the literal word "scan" into `make help`'s output column); the
# shared guard + docker compose recipe is attached separately so all four
# run the identical logic without duplicating it.
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
