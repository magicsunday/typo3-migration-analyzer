# Migration Mapping UI Integration Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Display detected old->new API migration mappings on the deprecation detail page so users can see which classes/methods have been renamed.

**Architecture:** Inject `MigrationMappingExtractor` into `DeprecationController::detail()`, extract mappings from the document's migration text, pass them to the template. Display as a card in the right sidebar below the existing Code-Referenzen card, showing source->target pairs with confidence badges.

**Tech Stack:** PHP 8.4, Symfony 7.2, Twig, Bootstrap 5.3

**Conventions:**
- `composer ci:cgl` and `composer ci:rector` before every commit
- `composer ci:test` must be green before every commit
- Code review after every commit, fix findings immediately
- Commit messages in English, no prefix convention, no Co-Authored-By
- English PHPDoc + English inline comments

---

### Task 1: Inject MigrationMappingExtractor into DeprecationController

**Files:**
- Modify: `src/Controller/DeprecationController.php:99-111`

**Step 1: Write the implementation**

Add `MigrationMappingExtractor` as a parameter to the `detail()` action (Symfony autowires it automatically). Extract mappings and pass to template.

```php
use App\Analyzer\MigrationMappingExtractor;

// In detail() method:
public function detail(
    string $filename,
    DocumentService $documentService,
    MigrationMappingExtractor $extractor,
): Response {
    $doc = $documentService->findDocumentByFilename($filename);

    if (!$doc instanceof RstDocument) {
        throw $this->createNotFoundException(sprintf('Document "%s" not found.', $filename));
    }

    return $this->render('deprecation/detail.html.twig', [
        'doc'      => $doc,
        'mappings' => $extractor->extract($doc->migration),
    ]);
}
```

**Step 2: Run tests**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass (no template changes yet, just passing extra data).

**Step 3: Commit**

```
Pass migration mappings to deprecation detail template
```

---

### Task 2: Display mappings in detail template

**Files:**
- Modify: `templates/deprecation/detail.html.twig:139-164`

**Step 1: Add mapping card to sidebar**

Insert a new card **above** the existing Code-Referenzen card (line 140) in the `col-lg-4` sidebar:

```twig
{% if mappings|length > 0 %}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent">
        <h6 class="card-title mb-0">
            <i class="bi bi-arrow-left-right me-1"></i>Migration-Mappings
            <span class="badge rounded-pill text-bg-primary ms-1">{{ mappings|length }}</span>
        </h6>
    </div>
    <div class="list-group list-group-flush">
        {% for mapping in mappings %}
        <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start mb-1">
                <span class="badge text-bg-danger">Alt</span>
                {% if mapping.confidence >= 1.0 %}
                    <span class="badge text-bg-success">{{ mapping.confidence|number_format(1) }}</span>
                {% elseif mapping.confidence >= 0.9 %}
                    <span class="badge text-bg-warning">{{ mapping.confidence|number_format(1) }}</span>
                {% else %}
                    <span class="badge text-bg-secondary">{{ mapping.confidence|number_format(1) }}</span>
                {% endif %}
            </div>
            <code class="small text-break">{{ mapping.source.className }}{% if mapping.source.member %}::{{ mapping.source.member }}{% endif %}</code>
            <div class="text-center my-1">
                <i class="bi bi-arrow-down text-muted"></i>
            </div>
            <div class="d-flex align-items-start">
                <span class="badge text-bg-success me-1">Neu</span>
            </div>
            <code class="small text-break">{{ mapping.target.className }}{% if mapping.target.member %}::{{ mapping.target.member }}{% endif %}</code>
        </div>
        {% endfor %}
    </div>
</div>
{% endif %}
```

**Step 2: Run tests**

Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 3: Commit**

```
Display migration mappings in deprecation detail sidebar
```

---

### Task 3: Code review and cleanup

**Step 1: Review all changes**

- Check controller for SOLID compliance
- Check template for XSS safety (Twig auto-escapes)
- Verify mapping card renders correctly with 0, 1, and multiple mappings
- Check that documents without migration text show no card

**Step 2: Fix any findings and commit**

```
Review findings: [describe fixes]
```
