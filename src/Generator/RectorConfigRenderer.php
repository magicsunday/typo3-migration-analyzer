<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Generator;

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\ClassConstFetch\RenameClassConstFetchRector;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Renaming\Rector\StaticCall\RenameStaticMethodRector;
use Rector\Renaming\ValueObject\MethodCallRename;
use Rector\Renaming\ValueObject\RenameClassAndConstFetch;
use Rector\Renaming\ValueObject\RenameStaticMethod;

use function array_unique;
use function array_values;
use function sort;
use function sprintf;
use function str_replace;

/**
 * Renders rector.php configuration files from structured rule entries.
 *
 * Accepts an array of rule entry arrays with type-specific keys and produces
 * a complete rector.php file with all necessary imports and configuration.
 *
 * Supported entry types:
 *   - rename_class: {type, old, new}
 *   - rename_method: {type, className, oldMethod, newMethod}
 *   - rename_static_method: {type, oldClass, oldMethod, newClass, newMethod}
 *   - rename_class_constant: {type, oldClass, oldConstant, newClass, newConstant}
 */
final class RectorConfigRenderer
{
    /**
     * Render a rector.php configuration file from rule entries.
     *
     * @param list<array<string, string>> $entries
     */
    public function render(array $entries): string
    {
        if ($entries === []) {
            return '';
        }

        $imports = [RectorConfig::class];
        $groups  = [];

        foreach ($entries as $entry) {
            [$shortName, $entryImports, $configLine] = $this->resolveEntry($entry);

            if ($shortName === '') {
                continue;
            }

            foreach ($entryImports as $import) {
                $imports[] = $import;
            }

            $groups[$shortName][] = $configLine;
        }

        if ($groups === []) {
            return '';
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $output = "<?php\n\ndeclare(strict_types=1);\n\n";

        foreach ($imports as $import) {
            $output .= sprintf("use %s;\n", $import);
        }

        $output .= "\nreturn RectorConfig::configure()\n";
        $output .= "    ->withConfiguredRules([\n";

        foreach ($groups as $shortName => $lines) {
            $output .= sprintf("        %s::class => [\n", $shortName);

            foreach ($lines as $line) {
                $output .= sprintf("            %s,\n", $line);
            }

            $output .= "        ],\n";
        }

        return $output . "    ]);\n";
    }

    /**
     * Resolve a single entry into Rector class name, imports, and config line.
     *
     * @param array<string, string> $entry
     *
     * @return array{string, list<string>, string}
     */
    private function resolveEntry(array $entry): array
    {
        return match ($entry['type'] ?? '') {
            'rename_class' => [
                'RenameClassRector',
                [RenameClassRector::class],
                sprintf("'%s' => '%s'", $this->escape($entry['old'] ?? ''), $this->escape($entry['new'] ?? '')),
            ],
            'rename_method' => [
                'RenameMethodRector',
                [RenameMethodRector::class, MethodCallRename::class],
                sprintf(
                    "new MethodCallRename('%s', '%s', '%s')",
                    $this->escape($entry['className'] ?? ''),
                    $this->escape($entry['oldMethod'] ?? ''),
                    $this->escape($entry['newMethod'] ?? ''),
                ),
            ],
            'rename_static_method' => [
                'RenameStaticMethodRector',
                [RenameStaticMethodRector::class, RenameStaticMethod::class],
                sprintf(
                    "new RenameStaticMethod('%s', '%s', '%s', '%s')",
                    $this->escape($entry['oldClass'] ?? ''),
                    $this->escape($entry['oldMethod'] ?? ''),
                    $this->escape($entry['newClass'] ?? ''),
                    $this->escape($entry['newMethod'] ?? ''),
                ),
            ],
            'rename_class_constant' => [
                'RenameClassConstFetchRector',
                [RenameClassConstFetchRector::class, RenameClassAndConstFetch::class],
                sprintf(
                    "new RenameClassAndConstFetch('%s', '%s', '%s', '%s')",
                    $this->escape($entry['oldClass'] ?? ''),
                    $this->escape($entry['oldConstant'] ?? ''),
                    $this->escape($entry['newClass'] ?? ''),
                    $this->escape($entry['newConstant'] ?? ''),
                ),
            ],
            default => ['', [], ''],
        };
    }

    /**
     * Escape single quotes and backslashes for PHP string literals.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
