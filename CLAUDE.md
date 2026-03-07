# TYPO3 Migration Analyzer

## Projekt-Kontext
- Symfony 7.2 Web-App die TYPO3 Deprecation/Breaking-Change RST-Dokumente parst
- Identifiziert fehlende Extension-Scanner-Matcher und generiert diese
- TYPO3 12 -> 13 Migration (spaeter erweiterbar auf 11 -> 12)
- Repo: https://github.com/magicsunday/typo3-migration-analyzer

## Tech Stack
- PHP 8.3+, Symfony 7.2, Twig, Turbo/Stimulus, AssetMapper
- symfony/property-info + symfony/property-access fuer Introspection
- typo3/cms-core + typo3/cms-install als Composer-Dependency (Datenquelle)
- PHPUnit, PHPStan Level 8, PHP-CS-Fixer (@PER-CS2.0 + @Symfony)
- Kein Webpack/Node — AssetMapper only
- Keine Datenbank — alles wird zur Laufzeit aus Dateien geparsed + gecacht

## Architektur
- `src/Dto/` — Value Objects (RstDocument, CodeReference, MatcherEntry, CoverageResult, Enums)
- `src/Parser/` — RstParser, RstFileLocator, MatcherConfigParser
- `src/Analyzer/` — MatcherCoverageAnalyzer
- `src/Generator/` — MatcherConfigGenerator
- `src/Service/` — DocumentService (Caching-Layer, zentrale Datenquelle)
- `src/Controller/` — Dashboard, Deprecation (List/Detail), Matcher (Analysis/Generate/Export)

## Entwicklungsprinzipien
- TDD (Test-Driven Development)
- KISS, SOLID, DRY, YAGNI
- Fein-granulare Commits
- Commit-Format: Beschreibender Text ohne Prefix-Convention

## Server starten
```bash
php -S localhost:8000 -t public/
```

## Bekannte Eigenheiten
- TYPO3 cms-composer-installers ueberschreibt public/index.php — Workaround via `extra.typo3/cms.web-dir` in composer.json
- Coverage aktuell bei ~51.7% (205 von 424 RST-Dokumenten ohne Matcher)

## Geplante Features (v1.1+)
- Rector-Rule-Skeleton-Generator
- Eigene Extension scannen (Upload oder Pfad-Angabe)
- Before/After Code-Vergleichsansicht
- Coverage-Report mit Prozentualer Aufschluesselung

## Design + Plan
- `docs/plans/2026-03-07-typo3-migration-analyzer-design.md`
- `docs/plans/2026-03-07-typo3-migration-analyzer-implementation.md`
