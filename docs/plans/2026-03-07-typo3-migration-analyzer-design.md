# Design: TYPO3 Migration Analyzer

## Zweck

Analyse- und Generator-Tool, das TYPO3 Deprecation/Breaking-Change RST-Dokumente parst und daraus:
1. Fehlende Extension-Scanner-Matcher identifiziert und generiert
2. Coverage-Reports erstellt (welche Deprecations sind abgedeckt?)

## Scope

- **v1.0:** TYPO3 12 -> 13 Migration
- **Spater:** 11 -> 12 nachrustbar

## Technologie-Stack

| Komponente         | Technologie                                              |
|--------------------|----------------------------------------------------------|
| Framework          | Symfony 7.2                                              |
| PHP                | 8.3+                                                     |
| UI                 | Twig + Symfony UX (Turbo, Stimulus)                      |
| Assets             | AssetMapper (kein Node/Webpack)                          |
| Introspection      | symfony/property-info, symfony/property-access, Reflection |
| Matcher-Configs    | Direktes include der PHP-Arrays aus typo3/cms-install    |
| TYPO3 Klassen      | typo3/cms-core + typo3/cms-install als Composer-Dependency |
| Tests              | PHPUnit                                                  |
| Code Style         | PHP-CS-Fixer (@PER-CS2.0 + @Symfony)                    |
| Static Analysis    | PHPStan Level 8                                          |

## Architektur

```
src/
  Parser/
    RstParser.php              # RST-Dateien parsen
    RstDocument.php            # Value Object: geparstes RST
    MatcherConfigParser.php    # Bestehende Matcher-Configs einlesen
    CodeReference.php          # VO: Klasse/Methode/Property-Referenz
  Analyzer/
    DeprecationAnalyzer.php    # RSTs analysieren, Referenzen extrahieren
    MatcherCoverageAnalyzer.php # RST <-> Matcher abgleichen
    ClassIntrospector.php      # PropertyInfo/Reflection: existiert Klasse noch?
  Generator/
    MatcherConfigGenerator.php # Matcher-Configs aus RST generieren
    MatcherType.php            # Enum: welcher Matcher-Typ passt
  Controller/
    DashboardController.php    # Ubersicht
    DeprecationController.php  # Detail-Ansicht Deprecations
    MatcherController.php      # Matcher-Analyse & Generator
    ExportController.php       # Download generierter Configs
  Twig/
    Components/                # Twig Components fur UI
templates/
  dashboard/
  deprecation/
  matcher/
  components/
```

## Datenfluss

```
RST-Dateien (typo3/cms-core)
        |
        v
    RstParser  --> RstDocument[]
        |
        v
 DeprecationAnalyzer  --> CodeReference[] (Klassen, Methoden, Properties)
        |
        |-> ClassIntrospector (PropertyInfo/Reflection)
        |         -> "Existiert noch?" / "Sichtbarkeit geandert?"
        |
        |-> MatcherCoverageAnalyzer
        |         -> Abgleich mit bestehenden Matcher-Configs
        |         -> Lucken-Report
        |
        -> MatcherConfigGenerator
                  -> PHP-Arrays fur fehlende Matcher
                  -> Export als .php Datei
```

## RST-Parser

Die RSTs haben eine konsistente Struktur mit ReST-Rollen. Der Parser extrahiert:

```php
class RstDocument {
    public string $type;           // 'Deprecation', 'Breaking', 'Feature'
    public int $issueId;
    public string $title;
    public string $version;        // z.B. '13.0'
    public string $description;
    public string $impact;
    public string $migration;
    public array $codeReferences;  // CodeReference[]
    public array $indexTags;       // ['Backend', 'FullyScanned', 'ext:core']
    public string $filename;
}
```

Code-Referenzen werden via Regex auf `:php:`-Rollen extrahiert:
```
":php:`\TYPO3\CMS\Core\Utility\GeneralUtility::hmac()`"
-> CodeReference(class: '...GeneralUtility', member: 'hmac', type: METHOD_STATIC)
```

## Matcher-Generierung

| Erkanntes Pattern              | Generierter Matcher          |
|--------------------------------|------------------------------|
| Klasse komplett entfernt       | ClassNameMatcher             |
| Methode entfernt (Instanz)     | MethodCallMatcher            |
| Methode entfernt (statisch)    | MethodCallStaticMatcher      |
| Konstante entfernt             | ConstantMatcher / ClassConstantMatcher |
| Property -> protected          | PropertyProtectedMatcher     |
| Argument entfernt              | MethodArgumentDroppedMatcher |

Komplexe Falle (TCA-Umbauten, Architektur-Anderungen) erzeugen nur einen Report.

## UI-Screens

1. **Dashboard** -- Kachelubersicht: Anzahl Deprecations, Breaking Changes, Matcher-Coverage (%), Lucken
2. **Deprecation-Liste** -- Tabelle mit Filter (Version, Typ, Scanned-Status), Suche, Sortierung
3. **Deprecation-Detail** -- RST-Inhalt gerendert, Code-Referenzen hervorgehoben, Matcher-Status
4. **Matcher-Analyse** -- Lucken-Report: welche RSTs haben keinen Matcher, Schwierigkeitsgrad
5. **Generator** -- Ausgewahlte Lucken -> Matcher generieren -> Preview -> Download als PHP

## v1.1+ (Nice-to-have)

- Rector-Rule-Skeleton-Generator
- Eigene Extension scannen (Upload oder Pfad-Angabe)
- Vergleichsansicht: Before/After Code aus den RSTs visualisieren
- Coverage-Report: Wie viel % der Deprecations sind durch Matcher/Rector abgedeckt?
