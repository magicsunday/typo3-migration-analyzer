# End-to-End Upgrade Pipeline — Design

## Ziel

Extension scannen → Findings mit passenden TYPO3-Changelog-Dokumenten verknüpfen → Rector-Rules zuordnen → priorisierter Aktionsplan mit Rector-Config-Export. Scope: Analyse + Rector-Config-Export (Rector-Ausführung für spätere Version vorgesehen).

## Architektur-Übersicht

```
Extension-Scan (besteht bereits)
    ↓ ScanResult (Findings mit Datei/Zeile)
Pattern-Erkennung (verbessert)
    ↓ MigrationMapping (Alt→Neu mit Confidence)
Rector-Zuordnung (besteht bereits)
    ↓ RectorRule (Config/Skeleton)
Aktionsplan-Generator (NEU)
    ↓ ActionPlan (priorisiert, gruppiert)
UI: Zwei Ansichten + Rector-Config-Export
```

## Kern-Verbesserung: Pattern-Erkennung (A+B integriert)

### Problem

Von 670 RST-Dokumenten mit `:php:`-Rollen in der Migration/Description werden nur 8 (1,2%) erfolgreich gemappt:

- **CodeReference zu strikt** — verwirft 78% der `:php:`-Werte (alles ohne FQCN)
- **Zu wenige Textmuster** — nur 4 Patterns, fehlende Connector-Typen
- **Nur Migration-Sektion** — Description enthält oft die konkreten Rename-Paare

### Lösung: Integrierter Ansatz A+B

**A: CodeReference lockern**

`CodeReference::fromPhpRole()` gibt nie mehr `null` zurück (außer für offensichtlichen Nonsens: `true`, `false`, `null`, `array`, `mixed`). Neue `CodeReferenceType`-Werte:

| Typ | Beispiel | Confidence |
|-----|----------|------------|
| `ClassName` (FQCN) | `\TYPO3\CMS\Core\Foo` | 1.0 |
| `ClassName` (short) | `ConfigurationView` | 0.7 |
| `StaticMethod` (FQCN) | `\Vendor\Class::method()` | 1.0 |
| `InstanceMethod` (FQCN) | `\Vendor\Class->method()` | 1.0 |
| `UnqualifiedMethod` | `getIdentifier()` | 0.5 |
| `Property` | `$property` | 0.6 |
| `ClassConstant` | `CONSTANT_NAME` | 0.6 |
| `ConfigKey` | `config.key` | 0.4 |

Neues Feld `CodeReference::$resolutionConfidence` (float 0.0–1.0).

**B: Neue Textmuster + erweiterter Scope**

MigrationMappingExtractor scannt **sowohl Migration als auch Description**. Neue Patterns zusätzlich zu den bestehenden 4:

| Pattern | Beispiel | Confidence |
|---------|----------|------------|
| `:php:\`Old\` to :php:\`New\`` | bare connector "to" | 0.9 |
| `has been moved to` | "Class has been moved to NewClass" | 1.0 |
| `has been changed to` | "Method has been changed to newMethod" | 0.9 |
| `is now available via` | "Feature is now available via NewClass" | 0.8 |
| `replaced by` / `has been replaced` | "Old was replaced by New" | 0.9 |
| `can be replaced by/with` | "Old can be replaced by New" | 0.9 |
| `should be replaced by` | "Old should be replaced by New" | 0.9 |

**Gesamt-Confidence** = Pattern-Confidence × Reference-Resolution-Confidence

**Downstream-Consumer entscheiden selbst:**
- MatcherConfigGenerator: Filtert auf FQCN (Confidence ≥ 0.9)
- RectorRuleGenerator: Filtert auf FQCN (Confidence ≥ 0.9)
- ComplexityScorer: Nutzt alles (auch niedrige Confidence → Score 1 für erkannte Renames)
- Aktionsplan: Zeigt Confidence an, sortiert danach

### Testfall #82744

```
Description: ":php:`TYPO3\CMS\Lowlevel\View\ConfigurationView` to :php:`TYPO3\CMS\Lowlevel\Controller\ConfigurationController`"
Migration:   "Use new class names instead."
```

- Description wird jetzt gescannt → `:php:`-Paar gefunden
- Bare "to"-Connector matcht neues Pattern
- Beide Werte sind FQCN → Resolution-Confidence 1.0
- Pattern-Confidence 0.9 → Gesamt 0.9
- **Ergebnis: 2 Mappings mit Confidence 0.9** ✓

### Testfall #72931

```
Title: "Breaking: #72931 - SearchFormController::pi_list_browseresults has been renamed"
Migration: "Rename `pi_list_browseresults` to `pi_list_browseResults`"
```

- Aktuell: `pi_list_browseresults` hat keinen Namespace → `fromPhpRole()` gibt `null` zurück
- Mit A+B: Wird als `UnqualifiedMethod` erkannt (Confidence 0.5)
- "Rename...to" matcht Pattern 1 → Pattern-Confidence 1.0
- Gesamt: 0.5 → ComplexityScorer erkennt es als Mapping → **Score 1** ✓

## Aktionsplan-Generator (NEU)

### Eingabe

- `ScanResult` (Findings aus Extension-Scan)
- Alle `RstDocument`s mit ihren `MigrationMapping`s und `ComplexityScore`s

### Ausgabe: `ActionPlan`

```php
final readonly class ActionPlan
{
    /** @param list<ActionItem> $items */
    public function __construct(
        public array $items,
        public ActionPlanSummary $summary,
    ) {}
}

final readonly class ActionItem
{
    public function __construct(
        public RstDocument $document,
        public ComplexityScore $complexity,
        public list<ScanFinding> $findings,        // Dateien/Zeilen aus dem Scan
        public list<MigrationMapping> $mappings,    // Alt→Neu Zuordnungen
        public list<RectorRule> $rectorRules,       // Passende Rector-Rules
        public AutomationGrade $automationGrade,    // full, partial, manual
    ) {}
}

enum AutomationGrade: string
{
    case Full    = 'full';     // Rector kann es komplett lösen
    case Partial = 'partial';  // Rector + manuelle Anpassung
    case Manual  = 'manual';   // Kein Rector-Support
}
```

### Priorisierung

1. **Automatisierbar zuerst** (Full → Partial → Manual)
2. **Innerhalb gleicher Stufe**: Nach Anzahl betroffener Dateien (absteigend)
3. **Innerhalb gleicher Dateizahl**: Nach Confidence (absteigend)

## UI: Zwei Ansichten

### Ansicht 1: Nach Automatisierungsgrad

Gruppiert nach `AutomationGrade`:

- **Vollautomatisch** (grün) — Rector-Config herunterladen, ausführen, fertig
- **Teilautomatisch** (gelb) — Rector löst Teile, Rest manuell
- **Manuell** (rot) — Kein Rector-Support, Anleitung aus RST-Dokument

Jede Gruppe zeigt:
- Dokument-Titel + Link zum Detail
- Betroffene Dateien/Zeilen
- Confidence-Badge
- Download-Button für Rector-Config (wenn verfügbar)

### Ansicht 2: Nach Datei

Gruppiert nach betroffener Extension-Datei:

- Dateiname + Gesamtzahl Findings
- Pro Finding: Zeile, betroffenes Dokument, Automatisierungsgrad
- Ermöglicht Datei-für-Datei-Abarbeitung

### Rector-Config-Export

- **Einzeln**: Pro Dokument (besteht bereits)
- **Gesamt**: Eine `rector.php` mit allen vollautomatischen Rules für den gesamten Scan
- Nur Rules, die tatsächlich zu Scan-Findings passen (nicht alle theoretisch möglichen)

## Scope für spätere Version

- **Rector-Ausführung**: Rector tatsächlich im Container ausführen und Diff anzeigen
- **Diff-Vorschau**: Vor/Nach-Vergleich der automatischen Änderungen
- **Batch-Export**: ZIP mit Rector-Config + Skeleton-Klassen für alle Findings
