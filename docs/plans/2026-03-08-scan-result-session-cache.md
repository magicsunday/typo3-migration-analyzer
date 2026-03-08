# Scan Result Session Cache Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Cache the last scan result in the session so exports work for all scan sources (path, ZIP, Git) and repeated Git clones are avoided.

**Architecture:** After each scan, store the serialized `ScanResult` in the Symfony session under a single key `scan_result`. Export routes read from session instead of re-scanning. The `clone()` action checks session for a matching URL before cloning. Template export links become simple GET routes without `?path=` parameter.

**Tech Stack:** PHP 8.4, Symfony 7.2 (Session), PHPUnit 12

---

### Task 1: Store scan result in session after each scan

**Files:**
- Modify: `src/Controller/ScanController.php`

**Step 1: Add session storage helper**

Add a private method and a session key constant to `ScanController`:

```php
/**
 * Session key for the last scan result.
 */
private const string SESSION_KEY_SCAN_RESULT = 'scan_result';

/**
 * Session key for the source identifier (path or URL) of the last scan.
 */
private const string SESSION_KEY_SCAN_SOURCE = 'scan_source';

/**
 * Store the scan result and its source identifier in the session.
 */
private function storeScanResult(Request $request, ScanResult $result, string $source): void
{
    $session = $request->getSession();
    $session->set(self::SESSION_KEY_SCAN_RESULT, $result);
    $session->set(self::SESSION_KEY_SCAN_SOURCE, $source);
}
```

**Step 2: Update `run()` to store result in session**

After `$result = $this->scanner->scan($extensionPath);`, add:

```php
$this->storeScanResult($request, $result, $extensionPath);
```

**Step 3: Update `upload()` to store result in session**

After `$result = $this->scanner->scan($extractedPath);` (inside the try block), add:

```php
$this->storeScanResult($request, $result, $file->getClientOriginalName());
```

**Step 4: Update `clone()` to check session cache and store result**

Before cloning, check if the session already has a result for this URL:

```php
$session = $request->getSession();
$cachedResult = $session->get(self::SESSION_KEY_SCAN_RESULT);
$cachedSource = $session->get(self::SESSION_KEY_SCAN_SOURCE);

if ($cachedResult instanceof ScanResult && $cachedSource === $repositoryUrl) {
    return $this->render('scan/result.html.twig', [
        'result' => $cachedResult,
    ]);
}
```

After scanning (inside try block), add:

```php
$this->storeScanResult($request, $result, $repositoryUrl);
```

**Step 5: Run CI**

Run: `docker compose exec phpfpm composer ci:cgl && docker compose exec phpfpm composer ci:rector`
Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Controller/ScanController.php
git commit -m "Store scan result in session after each scan"
```

---

### Task 2: Refactor export routes to use session

**Files:**
- Modify: `src/Controller/ScanController.php`

**Step 1: Replace `scanFromPath()` with `getSessionResult()`**

Remove the `scanFromPath()` method entirely. Add a new private method:

```php
/**
 * Retrieve the cached scan result from the session, or null if none exists.
 */
private function getSessionResult(Request $request): ?ScanResult
{
    $result = $request->getSession()->get(self::SESSION_KEY_SCAN_RESULT);

    if (!$result instanceof ScanResult) {
        $this->addFlash('danger', 'Kein Scan-Ergebnis vorhanden. Bitte zuerst einen Scan durchführen.');

        return null;
    }

    return $result;
}
```

**Step 2: Update all three export methods**

Replace the `$result = $this->scanFromPath(...)` call in each export method with:

```php
$result = $this->getSessionResult($request);
```

Remove the `$request->query->getString('path')` parameter usage — the methods still receive `Request` but only use the session now.

**Step 3: Remove unused imports**

After removing `scanFromPath()`, the `use function is_dir;` import is no longer needed in the controller (it was only used there and in `run()` — check if `run()` still uses it: yes it does, so keep it).

**Step 4: Run CI**

Run: `docker compose exec phpfpm composer ci:cgl && docker compose exec phpfpm composer ci:rector`
Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Controller/ScanController.php
git commit -m "Refactor export routes to read scan result from session"
```

---

### Task 3: Update template export links

**Files:**
- Modify: `templates/scan/result.html.twig`

**Step 1: Remove `?path=` from export links**

Change the export dropdown links (lines 23-31) from:

```twig
<li><a class="dropdown-item" href="{{ path('scan_export_json', {path: result.extensionPath}) }}">
```

To simply:

```twig
<li><a class="dropdown-item" href="{{ path('scan_export_json') }}">
```

Do the same for CSV and Markdown export links.

**Step 2: Run CI**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 3: Commit**

```bash
git add templates/scan/result.html.twig
git commit -m "Remove path parameter from export links, use session instead"
```

---

### Task 4: Update roadmap

**Files:**
- Modify: `CLAUDE.md`

**Step 1: Add session cache note to v1.3**

After the Export line in v1.3, add:

```
- [x] Session-basierter Scan-Result-Cache (Exports für alle Quellen, Clone-Deduplizierung)
```

**Step 2: Add multi-result cache to v2.1 or v2.2**

Add to v2.1 section:

```
- [ ] Multi-Result Session-Cache (mehrere Scan-Ergebnisse parallel vorhalten)
```

**Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "Add session cache to roadmap, plan multi-result for v2.1"
```
