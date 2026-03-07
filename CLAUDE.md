# TYPO3 Migration Analyzer

## Projekt-Kontext
- Symfony 7.2 Web-App die TYPO3 Deprecation/Breaking-Change RST-Dokumente parst
- Identifiziert fehlende Extension-Scanner-Matcher und generiert diese
- TYPO3 12 -> 13 Migration (später erweiterbar auf 11 -> 12)
- Repo: https://github.com/magicsunday/typo3-migration-analyzer

## Tech Stack
- PHP 8.3+, Symfony 7.2, Twig, Turbo/Stimulus, AssetMapper
- symfony/property-info + symfony/property-access für Introspection
- typo3/cms-core + typo3/cms-install als Composer-Dependency (Datenquelle)
- PHPUnit, PHPStan Level max, PHP-CS-Fixer (@PER-CS2x0 + @Symfony)
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
- KISS (Keep it simple, stupid!)
- SOLID (Single responsibility principle, Open-closed principle, Liskov substitution principle, Interface segregation principle, Dependency inversion principle)
- DRY (Don't repeat yourself)
- YAGNI (You aren't gonna need it)
- GRASP (General Responsibility Assignment Software Patterns)
- Law of Demeter, Separation of Concerns, Convention over Configuration
- Fein-granulare Commits
- Vor jedem Commit: `composer ci:cgl` und `composer ci:rector` ausführen, Änderungen übernehmen
- Vor jedem Commit MUSS `composer ci:test` grün sein
- Commit-Format: Beschreibender Text ohne Prefix-Convention, KEIN Co-Authored-By
- Immer korrekte deutsche Umlaute (ä, ö, ü, ß) verwenden, keine ASCII-Ersetzungen

## Server starten
```bash
php -S localhost:8000 -t public/
```

## Bekannte Eigenheiten
- TYPO3 cms-composer-installers überschreibt public/index.php — Workaround via `extra.typo3/cms.web-dir` in composer.json
- Coverage aktuell bei ~51.7% (205 von 424 RST-Dokumenten ohne Matcher)

## Roadmap

### v1.1 — Rector + Extension-Scan
- Rector-Rule-Skeleton-Generator
- Eigene Extension scannen (Upload oder Pfad-Angabe)
- Before/After Code-Vergleichsansicht aus RSTs
- Coverage-Report mit prozentualer Aufschlüsselung

### v1.2 — Intelligentere Analyse
- Argument-Erkennung aus RST-Code-Blöcken (korrekte numberOfMandatoryArguments)
- Migrations-Mapping: Alt->Neu Zuordnung parsen ("Replace X with Y")
- Komplexitäts-Scoring pro Deprecation (einfach automatisierbar vs. manuell)

### v1.3 — Extension-Scanner als Service
- Extension hochladen/scannen (ZIP oder Git-URL)
- Findings-Report mit Datei + Zeilennummer
- Export als JSON/CSV für Projektmanagement

### v2.0 — Multi-Version + Rector-Integration
- Beliebige TYPO3-Versionen (9->10, 10->11, 11->12, 12->13)
- Lauffähige Rector-Rules generieren (Rename Class/Method, Remove Argument)
- Rector-Config-Export (komplette rector.php)
- Diff gegen ssch/typo3-rector (was existiert schon, was fehlt)

### v2.1 — CI/CD Integration
- CLI-Modus: `bin/console analyze:extension /path/to/ext`
- GitHub Action für automatische Extension-Analyse bei PRs
- Composer Plugin: `composer typo3:migration-check`

### v3.0 — Community-Plattform
- Datenbank-Backend (Ergebnisse speichern, historisch vergleichen)
- Benutzer-Accounts (eigene Extensions verwalten)
- Generierte Matcher/Rules direkt als TYPO3 Core Patch oder typo3-rector PR einreichen
- Crowd-Validierung generierter Rules

## Design + Plan
- `docs/plans/2026-03-07-typo3-migration-analyzer-design.md`
- `docs/plans/2026-03-07-typo3-migration-analyzer-implementation.md`
