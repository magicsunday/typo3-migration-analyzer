# Before/After Code-Vergleich Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Code-Blöcke aus RST-Migrationsabschnitten extrahieren und mit Syntax-Highlighting als Before/After-Vergleich in der Detail-Ansicht darstellen.

**Architecture:** Ein `CodeBlockExtractor` parst `.. code-block::` RST-Direktiven aus dem Migrations-Text und erzeugt `CodeBlock`-DTOs mit Sprache, Code und Label (Before/After). Die Detail-Ansicht zeigt erkannte Paare nebeneinander mit highlight.js Syntax-Highlighting. highlight.js wird lokal unter `/libs/` eingebunden (wie Bootstrap).

**Tech Stack:** PHP 8.4, Symfony 7.2, highlight.js 11.x (lokal), Stimulus Controller, Twig

---

### Task 1: CodeBlock DTO

**Files:**
- Create: `src/Dto/CodeBlock.php`
- Create: `tests/Unit/Dto/CodeBlockTest.php`

**Step 1: Write the test**

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\CodeBlock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeBlockTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $block = new CodeBlock(
            language: 'php',
            code: 'echo "hello";',
            label: 'Before',
        );

        self::assertSame('php', $block->language);
        self::assertSame('echo "hello";', $block->code);
        self::assertSame('Before', $block->label);
    }

    #[Test]
    public function labelIsNullable(): void
    {
        $block = new CodeBlock(
            language: 'yaml',
            code: 'key: value',
            label: null,
        );

        self::assertNull($block->label);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Dto/CodeBlockTest.php`
Expected: FAIL — class `CodeBlock` not found

**Step 3: Write the implementation**

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Represents a single code block extracted from an RST document.
 */
final readonly class CodeBlock
{
    public function __construct(
        public string $language,
        public string $code,
        public ?string $label,
    ) {
    }
}
```

**Step 4: Run test to verify it passes**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Dto/CodeBlockTest.php`
Expected: PASS

**Step 5: Run CI + Commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add src/Dto/CodeBlock.php tests/Unit/Dto/CodeBlockTest.php
git commit -m "CodeBlock-DTO fuer extrahierte RST-Code-Bloecke"
git push
```

---

### Task 2: CodeBlockExtractor — Basis-Extraktion (TDD)

**Files:**
- Create: `src/Parser/CodeBlockExtractor.php`
- Create: `tests/Unit/Parser/CodeBlockExtractorTest.php`

**Step 1: Write the failing tests**

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Parser;

use App\Dto\CodeBlock;
use App\Parser\CodeBlockExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeBlockExtractorTest extends TestCase
{
    private CodeBlockExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new CodeBlockExtractor();
    }

    #[Test]
    public function extractSinglePhpCodeBlock(): void
    {
        $rst = <<<'RST'
        Add the method to your class.

        ..  code-block:: php

            public function getOptions(): array
            {
                return $this->options;
            }
        RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(1, $blocks);
        self::assertSame('php', $blocks[0]->language);
        self::assertStringContainsString('public function getOptions', $blocks[0]->code);
        self::assertNull($blocks[0]->label);
    }

    #[Test]
    public function extractMultipleCodeBlocks(): void
    {
        $rst = <<<'RST'
        Old code:

        ..  code-block:: php

            $old = true;

        New code:

        ..  code-block:: php

            $new = true;
        RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(2, $blocks);
        self::assertStringContainsString('$old', $blocks[0]->code);
        self::assertStringContainsString('$new', $blocks[1]->code);
    }

    #[Test]
    public function extractDifferentLanguages(): void
    {
        $rst = <<<'RST'
        Config example:

        ..  code-block:: yaml

            services:
              App\Service:
                tags: ['my.tag']
        RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(1, $blocks);
        self::assertSame('yaml', $blocks[0]->language);
        self::assertStringContainsString('services:', $blocks[0]->code);
    }

    #[Test]
    public function extractStripsCommonIndentation(): void
    {
        $rst = <<<'RST'
        Example:

        ..  code-block:: php

            if (true) {
                doSomething();
            }
        RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(1, $blocks);
        // Code should start at column 0, not be indented
        self::assertStringStartsWith('if (true)', $blocks[0]->code);
    }

    #[Test]
    public function extractReturnsEmptyArrayForNoCodeBlocks(): void
    {
        $rst = 'Just plain text without any code blocks.';

        self::assertSame([], $this->extractor->extract($rst));
    }

    #[Test]
    public function extractReturnsEmptyArrayForNullInput(): void
    {
        self::assertSame([], $this->extractor->extract(null));
    }

    #[Test]
    public function extractPreservesBlankLinesWithinCodeBlock(): void
    {
        $rst = <<<'RST'
        Example:

        ..  code-block:: php

            $a = 1;

            $b = 2;
        RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(1, $blocks);
        self::assertStringContainsString("\n\n", $blocks[0]->code);
    }

    #[Test]
    public function extractHandlesSingleDotSpaceDirective(): void
    {
        $rst = <<<'RST'
        Example:

        .. code-block:: php

            $x = 1;
        RST;

        $blocks = $this->extractor->extract($rst);

        self::assertCount(1, $blocks);
        self::assertSame('php', $blocks[0]->language);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Parser/CodeBlockExtractorTest.php`
Expected: FAIL — class `CodeBlockExtractor` not found

**Step 3: Write the implementation**

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Parser;

use App\Dto\CodeBlock;

use function array_pop;
use function count;
use function explode;
use function implode;
use function min;
use function preg_match;
use function strlen;
use function substr;
use function trim;

/**
 * Extracts code blocks from RST text by parsing `.. code-block::` directives.
 *
 * Detects explicit "Before" / "After" subsection headers and assigns labels accordingly.
 */
final class CodeBlockExtractor
{
    /**
     * Extract all code blocks from the given RST text.
     *
     * @return list<CodeBlock>
     */
    public function extract(?string $rstText): array
    {
        if ($rstText === null || trim($rstText) === '') {
            return [];
        }

        $lines     = explode("\n", $rstText);
        $lineCount = count($lines);
        $blocks    = [];

        $currentSubsection = null;
        $i                 = 0;

        while ($i < $lineCount) {
            $trimmed = trim($lines[$i]);

            // Detect RST subsection header (text line followed by underline)
            if (
                $trimmed !== ''
                && isset($lines[$i + 1])
                && preg_match('/^[\-=~`:.\'\"^_*+#]{3,}$/', trim($lines[$i + 1])) === 1
            ) {
                $currentSubsection = $trimmed;
                $i += 2;

                continue;
            }

            // Detect code-block directive
            if (preg_match('/^\.\.\s+code-block::\s+(\w+)/', $trimmed, $matches) === 1) {
                $language = $matches[1];
                $i++;

                // Skip blank lines after directive
                while ($i < $lineCount && trim($lines[$i]) === '') {
                    $i++;
                }

                // Collect indented lines (code content)
                $codeLines = [];

                while ($i < $lineCount) {
                    if (trim($lines[$i]) === '') {
                        $codeLines[] = '';
                        $i++;

                        continue;
                    }

                    if (preg_match('/^ {3,}/', $lines[$i]) !== 1) {
                        break;
                    }

                    $codeLines[] = $lines[$i];
                    $i++;
                }

                // Remove trailing blank lines
                while ($codeLines !== [] && trim($codeLines[count($codeLines) - 1]) === '') {
                    array_pop($codeLines);
                }

                $code = $this->stripCommonIndent($codeLines);

                // Determine label from current subsection context
                $label = null;

                if ($currentSubsection !== null && preg_match('/^(Before|After|Vorher|Nachher)$/i', $currentSubsection) === 1) {
                    $label = $currentSubsection;
                }

                $blocks[] = new CodeBlock(
                    language: $language,
                    code: trim($code),
                    label: $label,
                );

                continue;
            }

            $i++;
        }

        return $blocks;
    }

    /**
     * Strip the common leading whitespace from all non-empty lines.
     *
     * @param list<string> $lines
     */
    private function stripCommonIndent(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        // Find minimum indentation of non-empty lines
        $minIndent = PHP_INT_MAX;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            preg_match('/^( *)/', $line, $matches);
            $minIndent = min($minIndent, strlen($matches[1]));
        }

        if ($minIndent === PHP_INT_MAX || $minIndent === 0) {
            return implode("\n", $lines);
        }

        $stripped = [];

        foreach ($lines as $line) {
            $stripped[] = trim($line) === '' ? '' : substr($line, $minIndent);
        }

        return implode("\n", $stripped);
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Parser/CodeBlockExtractorTest.php`
Expected: All 8 tests PASS

**Step 5: Run CI + Commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add src/Parser/CodeBlockExtractor.php tests/Unit/Parser/CodeBlockExtractorTest.php
git commit -m "CodeBlockExtractor: RST-Code-Bloecke mit Sprache und Label extrahieren"
git push
```

---

### Task 3: CodeBlockExtractor — Before/After Label-Erkennung (TDD)

**Files:**
- Modify: `tests/Unit/Parser/CodeBlockExtractorTest.php`

**Step 1: Add tests for label detection**

Append to `CodeBlockExtractorTest`:

```php
#[Test]
public function extractDetectsExplicitBeforeAfterSubsections(): void
{
    $rst = <<<'RST'
    Replace old with new.

    Before
    ------

    .. code-block:: php

        $old = true;

    After
    -----

    .. code-block:: php

        $new = true;
    RST;

    $blocks = $this->extractor->extract($rst);

    self::assertCount(2, $blocks);
    self::assertSame('Before', $blocks[0]->label);
    self::assertSame('After', $blocks[1]->label);
}

#[Test]
public function extractResetsLabelAfterNonBeforeAfterSubsection(): void
{
    $rst = <<<'RST'
    Before
    ------

    .. code-block:: php

        $old = true;

    Details
    -------

    .. code-block:: php

        $other = true;
    RST;

    $blocks = $this->extractor->extract($rst);

    self::assertCount(2, $blocks);
    self::assertSame('Before', $blocks[0]->label);
    self::assertNull($blocks[1]->label);
}

#[Test]
public function extractHandlesBeforeAfterWithEqualsUnderline(): void
{
    $rst = <<<'RST'
    Before
    ======

    .. code-block:: php

        $old = true;

    After
    =====

    .. code-block:: php

        $new = true;
    RST;

    $blocks = $this->extractor->extract($rst);

    self::assertCount(2, $blocks);
    self::assertSame('Before', $blocks[0]->label);
    self::assertSame('After', $blocks[1]->label);
}
```

**Step 2: Run tests to verify they pass**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Parser/CodeBlockExtractorTest.php`
Expected: All 11 tests PASS (existing implementation already handles these cases)

**Step 3: Run CI + Commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add tests/Unit/Parser/CodeBlockExtractorTest.php
git commit -m "CodeBlockExtractor-Tests fuer Before/After Label-Erkennung"
git push
```

---

### Task 4: Integration in RstDocument + RstParser

**Files:**
- Modify: `src/Dto/RstDocument.php`
- Modify: `src/Parser/RstParser.php`
- Modify: `tests/Unit/Generator/RectorRuleGeneratorTest.php` (helper method)
- Modify: `tests/Unit/Generator/MatcherConfigGeneratorTest.php` (helper method)
- Modify: `tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php` (helper method)
- Modify: `tests/Unit/Dto/RstDocumentTest.php` (add codeBlocks assertions)

**Step 1: Add `codeBlocks` to `RstDocument`**

In `src/Dto/RstDocument.php`, add the new parameter with default `[]`:

```php
final readonly class RstDocument
{
    /**
     * @param list<CodeReference> $codeReferences
     * @param list<string>        $indexTags
     * @param list<CodeBlock>     $codeBlocks
     */
    public function __construct(
        public DocumentType $type,
        public int $issueId,
        public string $title,
        public string $version,
        public string $description,
        public ?string $impact,
        public ?string $migration,
        public array $codeReferences,
        public array $indexTags,
        public ScanStatus $scanStatus,
        public string $filename,
        public array $codeBlocks = [],
    ) {
    }
}
```

Add `use App\Dto\CodeBlock;` to the imports of RstDocument.

**Step 2: Inject `CodeBlockExtractor` into `RstParser`**

In `src/Parser/RstParser.php`:

1. Add constructor with `CodeBlockExtractor` dependency
2. Call extractor on migration text
3. Pass result to `RstDocument`

```php
use App\Parser\CodeBlockExtractor;

final class RstParser
{
    // ... existing constants ...

    public function __construct(
        private readonly CodeBlockExtractor $codeBlockExtractor = new CodeBlockExtractor(),
    ) {
    }

    public function parseFile(string $filePath, string $version): RstDocument
    {
        // ... existing code until return statement ...
        $migration = $this->extractSection($content, 'Migration');

        return new RstDocument(
            type: $this->extractType($filename),
            issueId: $this->extractIssueId($content),
            title: $this->extractTitle($content),
            version: $version,
            description: $this->extractSection($content, 'Description') ?? '',
            impact: $this->extractSection($content, 'Impact'),
            migration: $migration,
            codeReferences: $this->extractCodeReferences($content),
            indexTags: $this->extractIndexTags($content),
            scanStatus: $this->extractScanStatus($content),
            filename: $filename,
            codeBlocks: $this->codeBlockExtractor->extract($migration),
        );
    }
    // ... rest unchanged ...
}
```

**Step 3: Add test for integration**

In `tests/Unit/Dto/RstDocumentTest.php`, add a test:

```php
#[Test]
public function codeBlocksDefaultsToEmptyArray(): void
{
    $document = new RstDocument(
        type: DocumentType::Deprecation,
        issueId: 12345,
        title: 'Test',
        version: '13.0',
        description: 'Description',
        impact: null,
        migration: null,
        codeReferences: [],
        indexTags: [],
        scanStatus: ScanStatus::NotScanned,
        filename: 'Deprecation-12345-Test.rst',
    );

    self::assertSame([], $document->codeBlocks);
}
```

**Step 4: Run CI + Commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add src/Dto/RstDocument.php src/Parser/RstParser.php tests/
git commit -m "Code-Bloecke aus Migration-Abschnitt in RstDocument integrieren"
git push
```

---

### Task 5: highlight.js lokal einbinden

**Files:**
- Create: `public/libs/highlightjs/` (highlight.js Core + PHP language + Theme CSS)
- Modify: `templates/base.html.twig` (CSS-Link)
- Create: `assets/controllers/highlight_controller.js` (Stimulus Controller)
- Modify: `assets/app.js` (ggf. keine Änderung nötig — Stimulus auto-discovery)

**Step 1: highlight.js herunterladen**

```bash
# Im Container: highlight.js core + PHP + YAML + TypoScript herunterladen
docker compose exec -T phpfpm bash -c "
mkdir -p /var/www/public/libs/highlightjs
curl -sL 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/highlight.min.js' \
    -o /var/www/public/libs/highlightjs/highlight.min.js
curl -sL 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/languages/php.min.js' \
    -o /var/www/public/libs/highlightjs/php.min.js
curl -sL 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/languages/yaml.min.js' \
    -o /var/www/public/libs/highlightjs/yaml.min.js
curl -sL 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/languages/typescript.min.js' \
    -o /var/www/public/libs/highlightjs/typescript.min.js
curl -sL 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/atom-one-dark.min.css' \
    -o /var/www/public/libs/highlightjs/atom-one-dark.min.css
"
```

**Step 2: CSS in `base.html.twig` einbinden**

Nach der Bootstrap-Icons-Zeile hinzufügen:

```twig
<link href="/libs/highlightjs/atom-one-dark.min.css" rel="stylesheet">
```

**Step 3: highlight.js Scripts in `base.html.twig` einbinden**

Vor dem schließenden `</body>` tag, nach Bootstrap JS:

```html
<script src="/libs/highlightjs/highlight.min.js"></script>
<script src="/libs/highlightjs/php.min.js"></script>
<script src="/libs/highlightjs/yaml.min.js"></script>
<script src="/libs/highlightjs/typescript.min.js"></script>
<script>
document.addEventListener('turbo:load', function() {
    document.querySelectorAll('pre code.hljs-code').forEach(function(el) {
        hljs.highlightElement(el);
    });
});
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('pre code.hljs-code').forEach(function(el) {
        hljs.highlightElement(el);
    });
});
</script>
```

**Step 4: Commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add public/libs/highlightjs/ templates/base.html.twig
git commit -m "highlight.js lokal einbinden fuer Syntax-Highlighting"
git push
```

---

### Task 6: Detail-Template mit Code-Vergleich aktualisieren

**Files:**
- Modify: `templates/deprecation/detail.html.twig`
- Modify: `assets/styles/app.css` (Code-Vergleich Styles)

**Step 1: CSS für Code-Vergleich hinzufügen**

In `assets/styles/app.css` am Ende einfügen:

```css
/* Code comparison - Before/After */
.code-comparison {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.code-comparison .code-panel {
    min-width: 0;
}

.code-comparison .code-panel pre {
    background: #282c34;
    color: #abb2bf;
    padding: 1rem;
    border-radius: 0 0 0.375rem 0.375rem;
    font-size: 0.8125rem;
    line-height: 1.6;
    margin: 0;
    overflow-x: auto;
    white-space: pre;
    border: 1px solid #3e4451;
    border-top: 0;
}

.code-panel-header {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.5rem 1rem;
    border-radius: 0.375rem 0.375rem 0 0;
    border: 1px solid #3e4451;
    border-bottom: 0;
}

.code-panel-header.before {
    background: #3b1e1e;
    color: #e06c75;
}

.code-panel-header.after {
    background: #1e3b1e;
    color: #98c379;
}

.code-panel-header.neutral {
    background: #2c2c3a;
    color: #abb2bf;
}

/* Single code block (no comparison) */
.code-single pre {
    background: #282c34;
    color: #abb2bf;
    padding: 1rem;
    border-radius: 0 0 0.375rem 0.375rem;
    font-size: 0.8125rem;
    line-height: 1.6;
    margin: 0;
    overflow-x: auto;
    white-space: pre;
    border: 1px solid #3e4451;
    border-top: 0;
}

@media (max-width: 767.98px) {
    .code-comparison {
        grid-template-columns: 1fr;
    }
}
```

**Step 2: Template aktualisieren**

Ersetze den Migration-Abschnitt in `templates/deprecation/detail.html.twig`. Der neue Abschnitt zeigt:
1. Den Migration-Text (ohne Code-Block-Direktiven — raw RST bleibt als Fallback)
2. Darunter die extrahierten Code-Blöcke mit Highlighting

Ersetze den Migration `<div class="rst-section">` Block (Zeilen 57-62) mit:

```twig
{% if doc.migration %}
<div class="rst-section">
    <h6 class="text-uppercase text-muted small fw-bold mb-3">
        <i class="bi bi-arrow-right-circle me-1"></i>Migration
    </h6>
    <pre>{{ doc.migration }}</pre>
</div>
{% endif %}

{% if doc.codeBlocks|length > 0 %}
<div class="rst-section">
    <h6 class="text-uppercase text-muted small fw-bold mb-3">
        <i class="bi bi-code-slash me-1"></i>Code-Vergleich
        <span class="badge rounded-pill text-bg-primary ms-1">{{ doc.codeBlocks|length }}</span>
    </h6>

    {# Check if there are Before/After pairs #}
    {% set has_pairs = false %}
    {% for block in doc.codeBlocks %}
        {% if block.label matches '/^(Before|After)$/i' %}
            {% set has_pairs = true %}
        {% endif %}
    {% endfor %}

    {% if has_pairs %}
        {# Side-by-side comparison for Before/After pairs #}
        <div class="code-comparison mb-3">
            {% for block in doc.codeBlocks %}
                {% if block.label matches '/^Before$/i' %}
                    <div class="code-panel">
                        <div class="code-panel-header before">
                            <i class="bi bi-x-circle me-1"></i>Before (veraltet)
                        </div>
                        <pre><code class="hljs-code language-{{ block.language }}">{{ block.code }}</code></pre>
                    </div>
                {% elseif block.label matches '/^After$/i' %}
                    <div class="code-panel">
                        <div class="code-panel-header after">
                            <i class="bi bi-check-circle me-1"></i>After (neu)
                        </div>
                        <pre><code class="hljs-code language-{{ block.language }}">{{ block.code }}</code></pre>
                    </div>
                {% endif %}
            {% endfor %}
        </div>

        {# Show any unlabeled blocks separately #}
        {% for block in doc.codeBlocks %}
            {% if block.label is null %}
                <div class="code-single mb-3">
                    <div class="code-panel-header neutral">
                        <i class="bi bi-file-code me-1"></i>{{ block.language|upper }}
                    </div>
                    <pre><code class="hljs-code language-{{ block.language }}">{{ block.code }}</code></pre>
                </div>
            {% endif %}
        {% endfor %}
    {% else %}
        {# Sequential display for non-paired blocks #}
        {% for block in doc.codeBlocks %}
            <div class="code-single mb-3">
                <div class="code-panel-header neutral">
                    <i class="bi bi-file-code me-1"></i>{{ block.language|upper }}
                    {% if block.label %} — {{ block.label }}{% endif %}
                </div>
                <pre><code class="hljs-code language-{{ block.language }}">{{ block.code }}</code></pre>
            </div>
        {% endfor %}
    {% endif %}
</div>
{% endif %}
```

**Step 3: Commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add templates/deprecation/detail.html.twig assets/styles/app.css
git commit -m "Code-Vergleich in Detail-Ansicht mit Syntax-Highlighting"
git push
```

---

### Task 7: Integration-Test mit echten RST-Dateien

**Files:**
- Create: `tests/Integration/Parser/CodeBlockExtractorIntegrationTest.php`

**Step 1: Write integration test**

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Integration\Parser;

use App\Parser\CodeBlockExtractor;
use App\Parser\RstParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function glob;
use function is_string;

final class CodeBlockExtractorIntegrationTest extends TestCase
{
    private CodeBlockExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new CodeBlockExtractor();
    }

    #[Test]
    public function extractFromRealRstFileWithBeforeAfter(): void
    {
        // Deprecation-105213-TCASubTypes.rst has explicit Before/After sections
        $filePath = dirname(__DIR__, 3)
            . '/vendor/typo3/cms-core/Documentation/Changelog/13.4/Deprecation-105213-TCASubTypes.rst';

        $content = file_get_contents($filePath);
        self::assertIsString($content);

        // Extract the Migration section manually for testing
        $parser   = new RstParser();
        $document = $parser->parseFile($filePath, '13.4');

        self::assertNotNull($document->migration);
        self::assertNotEmpty($document->codeBlocks);

        // Should detect Before and After labels
        $labels = [];

        foreach ($document->codeBlocks as $block) {
            if (is_string($block->label)) {
                $labels[] = $block->label;
            }
        }

        self::assertContains('Before', $labels);
        self::assertContains('After', $labels);
    }

    #[Test]
    public function extractFromRealRstFileWithMultipleBlocks(): void
    {
        // Breaking-96044 has multiple sequential code blocks without explicit labels
        $filePath = dirname(__DIR__, 3)
            . '/vendor/typo3/cms-core/Documentation/Changelog/12.0/Breaking-96044-HardenMethodSignatureOfLogicalAndAndLogicalOr.rst';

        $parser   = new RstParser();
        $document = $parser->parseFile($filePath, '12.0');

        self::assertNotNull($document->migration);
        self::assertGreaterThanOrEqual(2, count($document->codeBlocks));

        foreach ($document->codeBlocks as $block) {
            self::assertSame('php', $block->language);
            self::assertNotEmpty($block->code);
        }
    }

    #[Test]
    public function allParsedDocumentsHaveValidCodeBlocks(): void
    {
        $changelogDir = dirname(__DIR__, 3)
            . '/vendor/typo3/cms-core/Documentation/Changelog/13.0/';

        $files = glob($changelogDir . '*.rst');
        self::assertNotEmpty($files);

        $parser = new RstParser();

        foreach ($files as $file) {
            $document = $parser->parseFile($file, '13.0');

            foreach ($document->codeBlocks as $block) {
                self::assertNotEmpty($block->language, 'Code block in ' . $document->filename . ' has empty language');
                self::assertNotEmpty($block->code, 'Code block in ' . $document->filename . ' has empty code');
            }
        }
    }
}
```

**Step 2: Run all tests**

Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All tests PASS

**Step 3: Commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add tests/Integration/Parser/CodeBlockExtractorIntegrationTest.php
git commit -m "Integration-Tests fuer CodeBlockExtractor mit echten RST-Dateien"
git push
```

---

### Task 8: Verifikation und CI

**Step 1: Volle CI-Suite laufen lassen**

```bash
docker compose exec -T phpfpm composer ci:test
```

Expected: Alle Tests grün, PHPStan 0 Fehler.

**Step 2: Manuell im Browser prüfen**

```bash
# Detail-Seite mit Before/After öffnen
curl -s http://localhost:8000/deprecations/Deprecation-105213-TCASubTypes.rst | grep -c "code-comparison"
# Erwartet: >= 1

# Detail-Seite mit mehreren Code-Blöcken
curl -s http://localhost:8000/deprecations/Breaking-96044-HardenMethodSignatureOfLogicalAndAndLogicalOr.rst | grep -c "hljs-code"
# Erwartet: >= 2
```

**Step 3: Commit falls nötig + Push**

```bash
git push
```
