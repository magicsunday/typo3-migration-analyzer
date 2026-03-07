# =============================================================================
# TARGETS
# =============================================================================

#### Helpers

.PHONY: check-compose info os-detect

check-compose: .logo ## Checks if docker and compose are available.
	$(MAKE) check-docker
	@echo -e "${FGREEN}Docker and Compose detected.${FRESET}"

info: .logo ## Prints out project information.
	@echo -e "\n${FBOLD}:: Project information${FRESET}\n"
	@echo -e "  ${FGREEN}Project name:${FRESET}\t\t${COMPOSE_PROJECT_NAME}"
	@echo -e "  ${FGREEN}Developer domain:${FRESET}\t${DEV_DOMAIN}"
	@echo -e "  ${FGREEN}Repository origin:${FRESET}\t$$(git remote get-url origin)"
	@echo -e "  ${FGREEN}Current branch:${FRESET}\t$$(git branch --show-current)"
	@latest=$$(git rev-list --tags --max-count=1 2>/dev/null); \
[ -n "$$latest" ] && tag=$$(git describe --tags $$latest 2>/dev/null) || tag="-"; \
echo -e "  ${FGREEN}Latest tag:${FRESET}\t\t$$tag"

	@echo -e "\n${FBOLD}:: Repository statistics${FRESET}\n"
	@echo -e "  ${FGREEN}Last commit message:${FRESET}\t$$(git log -1 --pretty=format:"%B")"
	@echo -e "  ${FGREEN}Last commit date:${FRESET}\t$$(git log -1 --pretty=format:"%cd")"
	@echo -e "  ${FGREEN}Last commit author:${FRESET}\t$$(git log -1 --pretty=format:"%an") <$$(git log -1 --pretty=format:"%ae")>"
	@echo -e "  ${FGREEN}Last commit id:${FRESET}\t$$(git log -1 --pretty=format:"%H")"
	@echo -e "  ${FGREEN}Count branches:${FRESET}\t$$(git branch -r | wc -l)"
	@echo -e "  ${FGREEN}Count tags:${FRESET}\t\t$$(git tag | wc -l)"
	@echo -e "  ${FGREEN}Count commits:${FRESET}\t$$(git rev-list --count HEAD)"

os-detect: .logo ## Prints basic OS/shell info for troubleshooting.
	@echo -e "Shell: $(SHELL)"
	@uname -a 2>/dev/null || echo "uname not available"
