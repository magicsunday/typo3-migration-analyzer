# TYPO3 Migration Analyzer

## Projekt-Kontext
- Symfony 7.2 Web-App die TYPO3 Deprecation/Breaking-Change RST-Dokumente parst
- Identifiziert fehlende Extension-Scanner-Matcher und generiert diese
- TYPO3 12 -> 13 Migration (später erweiterbar auf 11 -> 12)
- Repo: https://github.com/magicsunday/typo3-migration-analyzer

## Tech Stack
- PHP 8.4+, Symfony 7.2, Twig, Turbo/Stimulus, AssetMapper
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
- Nach jedem Commit: Code-Review durchführen und Findings sofort fixen
- Commit-Format: Beschreibender Text ohne Prefix-Convention, KEIN Co-Authored-By
- Immer korrekte deutsche Umlaute (ä, ö, ü, ß) verwenden, keine ASCII-Ersetzungen

## Coding-Richtlinien (PHP 8.4+ — aktiv PHP 8.4 Sprachfeatures nutzen)
- Interfaces verwenden wo sinnvoll
- Kein `@deprecated` — wenn etwas entfällt, direkt entfernen
- Tests für jede Klasse schreiben
- Keine `mixed`-Typen und `empty()`-Aufrufe
- `array_find`, `array_any` statt `foreach` wenn möglich
- Typdeklaration für Klassenkonstanten
- Redundante Type-Casts entfernen, wenn Typ bereits bekannt
- Unnötige geschweifte Klammern zur String-Interpolation entfernen
- Keine verschachtelten ternären Operatoren (Wartbarkeit)
- Null-Pointer-Ausnahmen prüfen und behandeln
- Fully-qualified Function-Calls durch `use function` Import ersetzen
- Klassen als `readonly` markieren wenn nur readonly-Properties, redundante `readonly`-Modifier entfernen
- Redundante Default-Argumente in Methodenaufrufen entfernen
- Statische Methoden nicht dynamisch (`->`) aufrufen
- Ungenutzte Methoden/Klassen prüfen und entfernen
- Immer nur eine Klasse je PHP-Datei
- Klassen/Methoden mit englischem PHPDoc-Block versehen (Beschreibung + Parameter)
- Erklärende Inline-Kommentare an komplexen Code-Stellen in Englisch
- Aussagekräftige Variablen- und Konstantennamen
- Konstanten verwenden wo sinnvoll

## Server starten
```bash
php -S localhost:8000 -t public/
```

## Bekannte Eigenheiten
- TYPO3 cms-composer-installers überschreibt public/index.php — Workaround via `extra.typo3/cms.web-dir` in composer.json
- Coverage aktuell bei ~51.7% (205 von 424 RST-Dokumenten ohne Matcher)

## Roadmap

Detaillierte Architektur und Komponenten-Beschreibungen: `docs/plans/2026-03-07-roadmap-v1.1-v2.1-design.md`

### v1.0 — Grundfunktionalität (fertig)
- RST-Parser, Matcher-Config-Parser, RstFileLocator
- Dashboard mit Coverage-Übersicht + Stat Cards
- Deprecation-Liste mit Suche/Filter + Detailansicht
- Matcher-Analyse + Generator (Configs generieren + ZIP-Export)
- DocumentService mit Caching
- Docker-Setup (PHP-FPM + Nginx + Traefik)
- CI-Toolchain (PHPStan, PHP-CS-Fixer, Rector, PHPUnit, jscpd)

### v1.1 — Rector + Extension-Scan + Visualisierung (~90% fertig)
- [x] Rector-Rule-Skeleton-Generator (Config + Skeleton + UI-Tabs + Export)
- [x] Migrations-Mapping-Extractor (Alt->Neu Paare aus RST, 4 Patterns, Confidence)
- [x] Extension-Scanner mit Pfad-Eingabe (21 TYPO3-Matcher, JSON-Export)
- [x] Before/After Code-Vergleich mit Syntax-Highlighting (highlight.js)
- [x] Coverage-Report (Breakdowns nach Version, Typ, Scan-Status, Matcher-Typ)
- [ ] Extension-Scanner: ZIP-Upload Support

### v1.2 — Intelligentere Analyse
- [x] Migrations-Mapping: Alt->Neu Zuordnung parsen (bereits in v1.1 umgesetzt)
- [ ] Argument-Erkennung aus RST-Code-Blöcken (korrekte numberOfMandatoryArguments/maximumNumberOfArguments)
- [ ] Komplexitäts-Scoring pro Deprecation (Score 1-5, automatisierbar vs. manuell)

### v1.3 — Extension-Scanner als Service
- [ ] ZIP-Upload (ZipUploadProvider, max 50MB, Validierung)
- [ ] Git-URL-Scan (GitRepositoryProvider, `git clone --depth 1`, Cleanup)
- [ ] Erweiterter Findings-Report (Code-Kontext, Gruppierung, Zusammenfassung)
- [ ] Export: JSON, CSV, Markdown

### v2.0 — Multi-Version + Rector-Integration
- [ ] Multi-Version Support (Versions-Bereich konfigurierbar, 9->10 bis 12->13)
- [ ] Lauffähige Rector-Rules generieren (komplette Rule-Klassen mit Tests)
- [ ] Rector-Config-Export (komplett, basierend auf Scan-Ergebnissen)
- [ ] Diff gegen ssch/typo3-rector (Abdeckung vergleichen, fehlende Rules identifizieren)

### v2.1 — CI/CD Integration
- [ ] CLI-Modus: `bin/console scan:extension`, `report:coverage`, `generate:matcher`, `generate:rector`
- [ ] GitHub Action: `magicsunday/typo3-migration-check-action`
- [ ] Composer Plugin: `magicsunday/typo3-migration-check-plugin`

### v3.0 — Community-Plattform
- [ ] Datenbank-Backend (Ergebnisse speichern, historisch vergleichen)
- [ ] Benutzer-Accounts (eigene Extensions verwalten)
- [ ] Generierte Matcher/Rules direkt als TYPO3 Core Patch oder typo3-rector PR einreichen
- [ ] Crowd-Validierung generierter Rules

## Design + Plan
- `docs/plans/2026-03-07-typo3-migration-analyzer-design.md` — Gesamtarchitektur
- `docs/plans/2026-03-07-typo3-migration-analyzer-implementation.md` — Implementierungsplan v1.0
- `docs/plans/2026-03-07-roadmap-v1.1-v2.1-design.md` — Detaillierte Roadmap v1.1-v2.1
- `docs/plans/2026-03-07-rector-rule-skeleton-generator.md` — Rector-Generator Plan
- `docs/plans/2026-03-07-extension-scanner.md` — Extension-Scanner Plan
- `docs/plans/2026-03-07-before-after-code-comparison.md` — Before/After Vergleich Plan
- `docs/plans/2026-03-07-coverage-report.md` — Coverage-Report Plan
